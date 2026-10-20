<?php

declare(strict_types=1);

namespace App\Services\Commerce;

use App\Exceptions\ApiException;
use App\Models\FeedPost;
use App\Models\User;
use App\Services\Media\ColorPalette;
use App\Services\Media\ImageGuard;
use App\Services\Media\VisualEmbedding;
use App\Services\Payments\CardPaymentService;
use App\Services\Payments\OrderPayments;
use App\Services\Payments\PaymentStep;
use App\Services\Search\VectorStore;
use App\Services\Vision\VisionCrossCheck;
use App\Services\Vision\VisionDescriber;
use App\Support\Currency;
use App\Support\SingleFlightCache;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Visual search ("scan") and one-tap buying.
 *
 * A scan runs in two layers:
 *  1. Trust and scale: the photo passes ImageGuard (type, size, declared pixels, optional client
 *     sha256) before anything decodes it, and vector candidates come from the HNSW index over
 *     searchable listings (active, verified sellers, not in review). One analysis per frame per minute.
 *  2. Cross-check: a vision describer names the product (category, colors, brand; a remote model when
 *     configured, local colors otherwise). A recognized category is matched on catalog tags; otherwise a
 *     vector neighbour must clear BOTH the luma and the structure threshold AND agree with the scan's
 *     category, brand and colors (VisionCrossCheck), so a different shoe is not returned in place of a
 *     slide. Weaker "semantic" matches stay in the same category and colors; the last fallback is the
 *     product tagged on a post the viewer can see. A weak match never falls back to a random product.
 * The three cheapest landed totals are returned, offers in the best match's currency first.
 */
final class ScanAndTapService
{
    /** A second photo of the same kind of shoe lands here. A different color still cannot cross it. */
    public const SEMANTIC_FLOOR = 0.40;

    /** Listings a category (tag) match considers, cheapest first: bounded work per scan at any catalog size. */
    private const TAG_CANDIDATES = 300;

    public function __construct(
        private readonly CardPaymentService $cards,
        private readonly Purchases $purchases,
        private readonly OrderPayments $payments,
        private readonly VectorStore $vectors,
        private readonly SingleFlightCache $cache,
        private readonly VisionDescriber $vision,
        private readonly VisionCrossCheck $crossCheck,
    ) {}

    private const ANALYZER = 'vision_crosscheck_v2';

    /** @return array<string, mixed> */
    public function search(User $user, string $bytes, ?string $category, ?string $postId = null, ?string $sha256 = null): array
    {
        $started = hrtime(true);
        if (strlen($bytes) > (int) config('commerce.visual_search.max_upload_bytes', 8 * 1024 * 1024)) {
            throw ApiException::imageInvalid('The image is too large.');
        }
        $hash = hash('sha256', $bytes);
        if ($sha256 !== null && $sha256 !== '' && ! hash_equals($hash, strtolower($sha256))) {
            throw ApiException::imageHashMismatch();
        }
        // The same frame scanned by many viewers (a viral reel) is validated, described and searched once
        // a minute (one vision-model call, not thousands). Only bytes that passed ImageGuard are ever
        // described or cached: a rejected image throws before the describer or the put.
        $analysis = $this->cache->remember('scan:v3:'.$hash, 60, 0, function () use ($bytes): array {
            $image = ImageGuard::decode($bytes);
            $seen = $this->vision->describe($bytes);
            try {
                $signature = VisualEmbedding::fromImage($image);
            } catch (ApiException $plain) {
                return ['seen' => $seen, 'candidates' => null, 'plain' => $plain->getMessage()];
            }
            $found = [];
            foreach ($this->vectors->nearest($signature, (int) config('commerce.visual_search.candidates', 64)) as $candidate) {
                $found[$candidate->product_id] = ['luma' => $candidate->luma_score, 'structure' => $candidate->structure_score];
            }

            return ['seen' => $seen, 'candidates' => $found, 'plain' => null];
        });
        $seen = $analysis['seen'];

        $picked = collect();
        $mode = 'none';
        if ($seen['category'] !== null) {
            $tagged = $this->byTags($user, $seen);
            $picked = $tagged['products'];
            $mode = $tagged['exact'] ? 'strict' : ($picked->isNotEmpty() ? 'semantic' : 'none');
        }
        if ($picked->isEmpty()) {
            if ($analysis['candidates'] === null) {
                // A plain photo the describer could not name: there is nothing to match on.
                throw ApiException::imageInvalid((string) $analysis['plain']);
            }
            $ranked = $this->ranked($analysis['candidates']);
            $byPrice = $this->cheapestFirst($user, $ranked);
            $lumaAt = (float) config('commerce.visual_search.luma_threshold', 0.85);
            $structureAt = (float) config('commerce.visual_search.structure_threshold', 0.80);
            $picked = $ranked
                ->filter(fn (object $product): bool => (float) $product->luminance_score >= $lumaAt
                    && (float) $product->structure_score >= $structureAt
                    && $this->crossCheck->accepts($seen, $product)
                    && $this->sameFamily($product, $seen))
                ->sort($byPrice)
                ->take(3)
                ->values();
            $mode = $picked->isEmpty() ? 'none' : 'strict';
            if ($picked->isEmpty()) {
                $picked = $this->semanticMatches($ranked, $seen, $byPrice);
                if ($picked->isNotEmpty()) {
                    $mode = 'semantic';
                }
            }
            if ($picked->isEmpty()) {
                $picked = $this->canonical($user, $postId)->filter(fn (object $product): bool => $this->crossCheck->accepts($seen, $product))->values();
                if ($picked->isNotEmpty()) {
                    $mode = 'canonical';
                }
            }
        }
        if ($picked->isNotEmpty() && $seen['category'] === null) {
            $seen['category'] = $this->text($picked->first()->vision_category ?? null);
        }

        $results = $picked->values()->map(function (object $product) use ($mode, $seen, $user): array {
            $similarity = $this->similarity($product);

            return [
                'product' => $this->card($product),
                'seller_id' => $product->seller_id,
                'score' => $similarity,
                'visual_similarity' => $similarity,
                'structure_similarity' => round((float) ($product->structure_score ?? 0), 4),
                'verified' => $mode === 'strict',
                'match' => $mode === 'strict' ? 'cross_checked' : $mode,
                'category_match' => $this->sameFamily($product, $seen),
                'landed' => LandedCost::quote($product, $user->country_code),
            ];
        })->filter(fn (array $row): bool => $row['visual_similarity'] >= self::SEMANTIC_FLOOR || $mode === 'canonical')->values();
        $labels = $results->map(fn (array $row): array => ['label' => (string) $row['product']['title'], 'score' => round((float) $row['score'], 4)])->all();
        $latency = (int) ((hrtime(true) - $started) / 1_000_000);
        $scanId = (string) Str::uuid7();
        DB::table('visual_scans')->insert([
            'id' => $scanId,
            'user_id' => $user->id,
            'image_sha256' => $hash,
            'analyzer' => self::ANALYZER,
            'detected_category' => $seen['category'],
            'labels' => json_encode($labels),
            'bounding_box' => null,
            'matched_product_ids' => '{'.implode(',', $results->pluck('product.id')->all()).'}',
            'fallback' => $mode !== 'strict',
            'latency_ms' => $latency,
            'created_at' => now(),
        ]);

        return [
            'scan_id' => $scanId,
            'analyzer' => self::ANALYZER,
            'models' => ['visual_v2_luma', 'visual_v2_structure'],
            'detected_category' => $seen['category'],
            'detected' => $seen,
            'exact_match' => $mode === 'strict',
            'labels' => $labels,
            'bounding_box' => null,
            'latency_ms' => $latency,
            'fallback' => $mode === 'strict' || $mode === 'none' ? null : $mode,
            'results' => $results->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function oneTap(User $buyer, string $productId, int $quantity, string $idempotencyKey): array
    {
        $existing = $this->purchases->existing($buyer, $idempotencyKey);
        if ($existing !== null && (string) $existing->product_id !== $productId) {
            throw ApiException::idempotencyConflict();
        }
        [$order, $step, $replayed] = $this->purchases->place($buyer, $idempotencyKey, 'one_tap', function () use ($productId, $quantity): array {
            $product = DB::table('products')->where('id', $productId)->lockForUpdate()->first();
            if ($product === null || $product->listing_status !== 'active' || (int) $product->stock < $quantity) {
                throw ApiException::outOfStock();
            }
            if (DB::update('UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?', [$quantity, $productId, $quantity]) !== 1) {
                throw ApiException::outOfStock();
            }

            return [
                'product_id' => $productId,
                'quantity' => $quantity,
                'unit_price_minor' => (int) $product->price_minor,
                'discount_bps' => 0,
                'total_minor' => (int) $product->price_minor * $quantity,
                'currency' => $product->currency,
            ];
        });

        return [
            'mode' => 'native',
            'order' => $this->order($order, $step),
            'next_action' => $step->nextAction,
            'replayed' => $replayed,
        ];
    }

    /** @return array<string, mixed> */
    public function showOrder(User $buyer, string $orderId): array
    {
        $order = $this->owned($buyer, $orderId);

        return $this->order($order, $this->payments->step($orderId));
    }

    /** After the app finishes 3-D Secure (or to refresh a pending payment): ask Stripe, apply, return. */
    public function syncOrder(User $buyer, string $orderId): array
    {
        $this->owned($buyer, $orderId);
        $step = $this->payments->sync($orderId);

        return $this->order(DB::table('orders')->where('id', $orderId)->first(), $step);
    }

    /** @return array<string, mixed> */
    public function cancel(User $buyer, string $orderId): array
    {
        $this->owned($buyer, $orderId);
        $this->payments->cancelByBuyer($buyer, $orderId);

        return $this->order(DB::table('orders')->where('id', $orderId)->first(), $this->payments->step($orderId));
    }

    /** @return array<string, mixed> */
    public function paymentMethod(User $user): array
    {
        return $this->cards->summary($user);
    }

    private function owned(User $buyer, string $orderId): object
    {
        $order = Str::isUuid($orderId) ? DB::table('orders')->where('id', $orderId)->where('buyer_id', $buyer->id)->first() : null;
        if ($order === null) {
            throw ApiException::orderNotFound();
        }

        return $order;
    }

    /** @return array<string, mixed> */
    private function order(object $order, PaymentStep $step): array
    {
        $auth = DB::table('payment_authorizations')->where('order_id', $order->id)->orderByDesc('created_at')->first();
        $product = DB::table('products')->where('id', $order->product_id)->first();
        $status = match ($order->status) {
            'paid' => 'completed',
            'cancelled' => 'cancelled',
            default => 'processing',
        };
        $undo = $auth !== null && in_array($auth->status, ['pending', 'requires_action', 'authorized'], true) && $auth->capture_after !== null
            ? (string) $auth->capture_after
            : null;

        return [
            'id' => $order->id,
            'status' => $status,
            'payment_status' => $step->paymentStatus,
            'next_action' => $step->nextAction,
            'failure_code' => $step->failureCode,
            'undo_until' => $undo,
            'withdrawal_deadline' => $order->delivered_at === null ? null : (string) now()->parse($order->delivered_at)->addDays(14),
            'total' => Currency::money((int) $order->total_minor, (string) $order->currency),
            'refunded' => Currency::money((int) $order->amount_refunded_minor, (string) $order->currency),
            'item' => [
                'product_id' => $order->product_id,
                'title' => $product->title ?? '',
                'variant_sku' => 'default',
                'size_label' => 'One size',
                'quantity' => (int) $order->quantity,
            ],
            'placed_at' => (string) $order->created_at,
        ];
    }

    /** @return array<string, mixed> */
    private function card(object $product): array
    {
        $inStock = (int) $product->stock > 0;

        return [
            'id' => $product->id,
            'slug' => $product->slug,
            'title' => $product->title,
            'category' => 'apparel',
            'image_url' => $product->image_url,
            'images' => [$product->image_url],
            'price' => Currency::money((int) $product->price_minor, (string) $product->currency),
            'compare_at_price' => null,
            'price_as_of' => null,
            'variants' => [[
                'sku' => 'default',
                'label' => 'One size',
                'in_stock' => $inStock,
            ]],
            'merchant' => ['id' => $product->seller_id, 'name' => $product->brand],
            'purchase' => ['mode' => $inStock ? 'one_tap' : 'unavailable', 'in_stock' => $inStock],
        ];
    }

    private function landedTotal(object $product, User $user): int
    {
        return LandedCost::quote($product, $user->country_code)['total']['amount_minor'];
    }

    /**
     * The product tagged on a post the viewer is allowed to see (a hidden or deleted post, or a blocked
     * author, gives nothing away).
     *
     * @return Collection<int, object>
     */
    private function canonical(User $viewer, ?string $postId): Collection
    {
        if ($postId === null || ! Str::isUuid($postId)
            || ! FeedPost::query()->visibleTo($viewer)->withoutBlockedFor($viewer)->whereKey($postId)->exists()) {
            return collect();
        }

        return collect(DB::select(
            "SELECT p.*, 0::float8 AS luminance_score, 0::float8 AS structure_score
               FROM products p
               JOIN reel_product_tags t ON t.product_id = p.id
              WHERE t.post_id = ? AND p.stock > 0 AND p.listing_status = 'active'
              ORDER BY p.id
              LIMIT 1",
            [$postId],
        ));
    }

    /**
     * Vector candidates as live product rows: still in stock, still active, seller still verified
     * (the cached candidate list may be up to a minute old).
     *
     * @param  array<string, array{luma: float, structure: float}>  $candidates
     * @return Collection<int, object>
     */
    private function ranked(array $candidates): Collection
    {
        if ($candidates === []) {
            return collect();
        }

        return $this->buyable(DB::table('products as p'))
            ->whereIn('p.id', array_keys($candidates))
            ->get(['p.*'])
            ->map(function (object $product) use ($candidates): object {
                $product->luminance_score = $candidates[$product->id]['luma'];
                $product->structure_score = $candidates[$product->id]['structure'];

                return $product;
            });
    }

    /** Listings a buyer may be shown: in stock, active (not in review), platform or verified seller. */
    private function buyable(Builder $query): Builder
    {
        return $query
            ->leftJoin('sellers as s', 's.user_id', '=', 'p.seller_id')
            ->where('p.stock', '>', 0)
            ->where('p.listing_status', 'active')
            ->where(fn (Builder $seller) => $seller->whereNull('p.seller_id')->orWhere('s.status', 'verified'));
    }

    /**
     * Cheapest landed total first, comparing like with like: offers in the best match's currency lead.
     *
     * @param  Collection<int, object>  $pool
     * @return Closure(object, object): int
     */
    private function cheapestFirst(User $user, Collection $pool): Closure
    {
        $preferred = $pool->sortByDesc(fn (object $product): float => $this->similarity($product))->first()?->currency;

        return fn (object $left, object $right): int => [
            $left->currency !== $preferred, $this->landedTotal($left, $user), (string) $left->id,
        ] <=> [
            $right->currency !== $preferred, $this->landedTotal($right, $user), (string) $right->id,
        ];
    }

    /**
     * @param  Collection<int, object>  $ranked
     * @param  array{category: ?string, colors: list<string>, brand: ?string, features: list<string>, confidence: float, source: string}  $seen
     * @param  Closure(object, object): int  $byPrice
     * @return Collection<int, object>
     */
    private function semanticMatches(Collection $ranked, array &$seen, Closure $byPrice): Collection
    {
        $near = $ranked->filter(function (object $product) use ($seen): bool {
            if ((float) $product->structure_score < self::SEMANTIC_FLOOR) {
                return false;
            }
            if (! ColorPalette::agrees($seen['colors'], ColorPalette::parse($product->vision_colors ?? null))) {
                return false;
            }
            if ($seen['category'] !== null && $this->text($product->vision_category ?? null) !== $seen['category']) {
                return false;
            }
            if ((float) $seen['confidence'] >= VisionCrossCheck::CONFIDENT && $seen['brand'] !== null && $this->text($product->vision_brand ?? null) !== $seen['brand']) {
                return false;
            }

            return $this->text($product->vision_category ?? null) !== null && $this->sameFamily($product, $seen);
        });
        if ($seen['category'] === null) {
            $best = $near->sortByDesc(fn (object $product): float => (float) $product->structure_score)->first();
            if ($best === null) {
                return collect();
            }
            $seen['category'] = $this->text($best->vision_category ?? null);
            $seen['confidence'] = round((float) $best->structure_score, 4);
            if ($seen['source'] === 'local') {
                $seen['source'] = 'catalog';
            }
            $near = $near->filter(fn (object $product): bool => $this->text($product->vision_category ?? null) === $seen['category']);
        }

        return $near->sort($byPrice)->take(3)->values();
    }

    /**
     * A slides result stays in Footwear/Slides. It does not include running shoes or coats.
     *
     * @param  array{category: ?string, colors: list<string>, brand: ?string, features: list<string>, confidence: float, source: string}  $seen
     */
    private function sameFamily(object $product, array $seen): bool
    {
        $detected = $seen['category'];
        if ($detected === null) {
            return true;
        }
        $productCategory = $this->text($product->vision_category ?? null);

        return $productCategory !== null && $productCategory === $detected;
    }

    /**
     * Listings of the recognized category, scored on catalog tags (colors, brand). Bounded to the
     * TAG_CANDIDATES cheapest buyable listings of that category (expression index on lower(vision_category)).
     *
     * @param  array{category: ?string, colors: list<string>, brand: ?string, features: list<string>, confidence: float, source: string}  $seen
     * @return array{exact: bool, products: Collection<int, object>}
     */
    private function byTags(User $user, array $seen): array
    {
        $rows = $this->buyable(DB::table('products as p'))
            ->whereRaw('lower(p.vision_category) = ?', [$seen['category']])
            ->orderBy('p.price_minor')
            ->orderBy('p.id')
            ->limit(self::TAG_CANDIDATES)
            ->get(['p.*']);
        $scored = $rows->map(function (object $product) use ($seen): object {
            $score = $this->tagScore($seen, $product);
            $product->luminance_score = $score;
            $product->structure_score = $score;

            return $product;
        });
        $exact = $scored->filter(fn (object $product): bool => $this->tagScore($seen, $product) >= 0.95);
        $chosen = $exact->isNotEmpty() ? $exact : $scored;

        return [
            'exact' => $exact->isNotEmpty(),
            'products' => $chosen->sort($this->cheapestFirst($user, $chosen))->take(3)->values(),
        ];
    }

    /** @param  array{category: ?string, colors: list<string>, brand: ?string, features: list<string>, confidence: float, source: string}  $seen */
    private function tagScore(array $seen, object $product): float
    {
        $score = 0.55;
        if (ColorPalette::agrees($seen['colors'], ColorPalette::parse($product->vision_colors ?? null))) {
            $score += 0.25;
        }
        if ($seen['brand'] !== null && $this->text($product->vision_brand ?? null) === $seen['brand']) {
            $score += 0.2;
        }

        return min(1.0, $score);
    }

    private function similarity(object $product): float
    {
        $luminance = (float) ($product->luminance_score ?? 0);
        $structure = (float) ($product->structure_score ?? 0);
        if ($luminance <= 0.0 && $structure <= 0.0) {
            return 0.0;
        }

        return round(min($luminance > 0 ? $luminance : $structure, $structure > 0 ? $structure : $luminance), 4);
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return strtolower($value);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\FeedPost;
use App\Models\Product;
use App\Services\Commerce\CheckoutService;
use App\Services\Commerce\RewardService;
use App\Services\Feed\FeedService;
use App\Services\Feed\TasteService;
use App\Support\Currency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class CommerceController extends Controller
{
    public function __construct(
        private readonly CheckoutService $checkout,
        private readonly RewardService $rewards,
        private readonly FeedService $feed,
        private readonly TasteService $taste,
    ) {}

    public function checkout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'uuid'],
            'post_id' => ['nullable', 'uuid'],
            'squad_id' => ['nullable', 'uuid'],
            'coupon_id' => ['nullable', 'uuid'],
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:3'],
            'referral_token' => ['nullable', 'string', 'regex:/^[A-Za-z0-9]{10,32}$/'],
            'share_publicly' => ['sometimes', 'boolean'],
            'expected_total_minor' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'address_id' => ['sometimes', 'nullable', 'uuid'],
        ]);
        $key = $this->idempotencyKey($request);

        $order = $this->checkout->checkout(
            $request->user(),
            $data['product_id'],
            $data['post_id'] ?? null,
            $data['squad_id'] ?? null,
            $data['coupon_id'] ?? null,
            (int) ($data['quantity'] ?? 1),
            $key,
            $data['referral_token'] ?? null,
            (bool) ($data['share_publicly'] ?? false),
            isset($data['expected_total_minor']) ? (int) $data['expected_total_minor'] : null,
            $data['address_id'] ?? null,
        );

        return new JsonResponse(['data' => $order], $order['replayed'] ? 200 : 201);
    }

    public function openSquad(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'uuid'],
            'post_id' => ['nullable', 'uuid'],
        ]);

        return new JsonResponse(['data' => $this->checkout->openSquad($request->user(), $data['product_id'], $data['post_id'] ?? null)], 201);
    }

    public function joinSquad(Request $request, string $squad): JsonResponse
    {
        return new JsonResponse(['data' => $this->checkout->joinSquad($request->user(), $squad)]);
    }

    public function rewards(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->rewards->wallet($request->user())]);
    }

    public function loot(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->rewards->openLootBox($request->user())]);
    }

    public function watch(Request $request, string $post): JsonResponse
    {
        $data = $request->validate([
            'watched_ms' => ['required', 'integer', 'min:0', 'max:600000'],
            'duration_ms' => ['required', 'integer', 'min:1', 'max:600000'],
        ]);
        $this->feed->visiblePost($request->user(), $post);
        $granted = $this->rewards->recordWatch($request->user(), $post, (int) $data['watched_ms'], (int) $data['duration_ms']);
        if ($granted) {
            $this->taste->observe($request->user(), $post, 'watch');
        }

        return new JsonResponse(['data' => ['rewarded' => $granted]]);
    }

    public function activity(Request $request): JsonResponse
    {
        $viewer = $request->user();
        $rows = DB::table('activity_events as ae')
            ->join('users as u', 'u.id', '=', 'ae.actor_id')
            ->join('products as p', 'p.id', '=', 'ae.product_id')
            ->where('ae.actor_id', '<>', $viewer->id)
            ->where('ae.created_at', '>=', now()->subMinutes(30))
            ->where(function ($query) use ($viewer): void {
                $id = $viewer->id;
                $query->whereExists(function ($friends) use ($id): void {
                    $friends->selectRaw('1')
                        ->from('social_connections as sc')
                        ->where('sc.status', 'accepted')
                        ->whereRaw('LEAST(sc.requester_id, sc.addressee_id) = LEAST(ae.actor_id, ?::uuid)', [$id])
                        ->whereRaw('GREATEST(sc.requester_id, sc.addressee_id) = GREATEST(ae.actor_id, ?::uuid)', [$id]);
                })->orWhere('ae.created_at', '>=', now()->subMinutes(5));
            })
            ->orderByDesc('ae.created_at')
            ->limit(8)
            ->get(['ae.id', 'u.username', 'p.title', 'ae.created_at']);

        return new JsonResponse(['data' => $rows->map(fn (object $row): array => [
            'id' => $row->id,
            'username' => $row->username,
            'product_title' => $row->title,
            'created_at' => (string) $row->created_at,
        ])->all()]);
    }

    public function tryOn(string $product): JsonResponse
    {
        $item = Product::query()->whereKey($product)->first();
        if ($item === null) {
            abort(404);
        }

        return new JsonResponse(['data' => [
            'product_id' => $item->id,
            'available' => (bool) $item->try_on_enabled,
            'mode' => 'ar_hook',
            'asset_url' => $item->try_on_enabled ? $item->image_url : null,
        ]]);
    }

    public function store(Request $request, string $user): JsonResponse
    {
        $postIds = FeedPost::query()->visibleTo($request->user())->where('user_id', $user)->pluck('id');
        $products = Product::query()
            ->whereIn('id', function ($query) use ($postIds): void {
                $query->select('product_id')->from('reel_product_tags')->whereIn('post_id', $postIds);
            })
            ->orderBy('title')
            ->get();

        return new JsonResponse(['data' => $products->map(fn (Product $product): array => [
            'id' => $product->id,
            'title' => $product->title,
            'brand' => $product->brand,
            'image_url' => $product->image_url,
            'try_on' => (bool) $product->try_on_enabled,
            'price' => Currency::money((int) $product->price_minor, (string) $product->currency),
        ])->all()]);
    }

    /**
     * The live flash drop, if any. Drops are created by the scheduler (drops:rotate) under
     * commerce.flash_drops; an API request can no longer start a 40%-off drop on demand.
     */
    public function ensureDrop(): JsonResponse
    {
        $live = DB::table('flash_drops')
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now())
            ->where('stock_remaining', '>', 0)
            ->orderBy('ends_at')
            ->first(['id', 'product_id', 'discount_bps', 'stock_remaining', 'ends_at']);

        return new JsonResponse(['data' => [
            'ok' => true,
            'drop' => $live === null ? null : [
                'id' => $live->id,
                'product_id' => $live->product_id,
                'discount_bps' => (int) $live->discount_bps,
                'remaining' => (int) $live->stock_remaining,
                'ends_at' => (string) $live->ends_at,
            ],
        ]]);
    }
}

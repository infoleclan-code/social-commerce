<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\FeedPost;
use App\Services\Commerce\LandedCost;
use App\Services\Commerce\PriceGuard;
use App\Services\Commerce\SyntheticMediaService;
use App\Support\MediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/**
 * One reel. `viewer.liked` is the requester's own state; who else liked is never exposed.
 */
final class FeedPostResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var FeedPost $post */
        $post = $this->resource;
        $urls = app(MediaUrl::class);

        return [
            'id' => $post->id,
            'kind' => $post->kind->value,
            'author' => new UserSummaryResource($post->user),
            'media' => $post->media_path === null ? null : [
                'type' => $post->media_type?->value,
                'url' => $urls->for($post->media_path),
                'thumbnail_url' => $urls->for($post->thumbnail_path),
                'width' => $post->media_width,
                'height' => $post->media_height,
                'duration_ms' => $post->media_duration_ms,
            ],
            'caption' => $post->caption,
            'tags' => $post->tags ?? [],
            'audience' => $post->audience->value,
            'stats' => [
                'likes' => $post->likes_count,
                'comments' => $post->comments_count,
                'shares' => $post->shares_count,
            ],
            'viewer' => [
                'liked' => (bool) ($post->getAttributes()['liked_by_me'] ?? false),
                'is_author' => $post->user_id === $request->user()?->getKey(),
            ],
            'created_at' => $post->created_at?->toIso8601ZuluString('millisecond'),
            'shop' => $this->shop($post, $request),
            'synthetic' => $this->synthetic($post),
        ];
    }

    /** @return array<string, mixed>|null */
    private function shop(FeedPost $post, Request $request): ?array
    {
        $tag = $post->shopTag;
        $product = $tag?->product;
        if ($tag === null || $product === null) {
            return null;
        }
        $drop = $product->liveDrops->sortBy('ends_at')->first();

        return [
            'commission_bps' => (int) $tag->commission_bps,
            'source' => (string) ($tag->source ?? 'manual'),
            'price_protection_days' => PriceGuard::WINDOW_DAYS,
            'product' => [
                'id' => $product->id,
                'title' => $product->title,
                'brand' => $product->brand,
                'image_url' => $product->image_url,
                'try_on' => (bool) $product->try_on_enabled,
                'stock' => (int) $product->stock,
                'price' => [
                    'amount_minor' => (int) $product->price_minor,
                    'currency' => $product->currency,
                    'minor_unit' => 2,
                ],
                'squad_price' => [
                    'amount_minor' => (int) $product->squad_price_minor,
                    'currency' => $product->currency,
                    'minor_unit' => 2,
                ],
            ],
            'flash' => $drop === null ? null : [
                'id' => $drop->id,
                'discount_bps' => (int) $drop->discount_bps,
                'stock_remaining' => (int) $drop->stock_remaining,
                'ends_at' => $drop->ends_at?->toIso8601ZuluString('millisecond'),
            ],
            'drop_hour' => $this->dropHour($product->id, (string) $product->currency),
            'landed' => LandedCost::quote($product, $request->user()?->country_code),
        ];
    }

    /** @return array<string, mixed>|null */
    private function synthetic(FeedPost $post): ?array
    {
        $payload = app(SyntheticMediaService::class)->forPost((string) $post->id);

        return $payload['tracks'] === [] ? null : $payload;
    }

    /** @return array<string, mixed>|null */
    private function dropHour(string $productId, string $currency): ?array
    {
        $hour = DB::table('drop_hours')
            ->where('product_id', $productId)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now())
            ->orderBy('ends_at')
            ->first();
        if ($hour === null) {
            return null;
        }

        return [
            'id' => $hour->id,
            'ends_at' => (string) $hour->ends_at,
            'remaining' => (int) $hour->stock - (int) $hour->reserved,
            'price' => [
                'amount_minor' => (int) $hour->price_minor,
                'currency' => $currency,
                'minor_unit' => 2,
            ],
        ];
    }
}

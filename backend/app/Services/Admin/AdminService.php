<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Enums\ListingStatus;
use App\Enums\RiskStatus;
use App\Enums\SellerStatus;
use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Models\FeedPost;
use App\Models\FeedPostComment;
use App\Models\Product;
use App\Models\Seller;
use App\Models\User;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\PaymentGatewayUnavailable;
use App\Services\Payments\PaymentRejected;
use App\Support\Currency;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Staff tools: auctions, the catalog, seller verification, and moderation.
 * Responses are plain arrays. Controllers never return models.
 */
final class AdminService
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    /** @return list<array<string, mixed>> */
    public function auctions(): array
    {
        return DB::table('auctions as a')
            ->join('products as p', 'p.id', '=', 'a.product_id')
            ->leftJoin('users as u', 'u.id', '=', 'a.leader_id')
            ->orderByDesc('a.ends_at')
            ->limit(30)
            ->get(['a.id', 'a.product_id', 'p.title', 'p.currency', 'a.start_minor', 'a.current_minor', 'a.starts_at', 'a.ends_at', 'a.ended_at', 'u.username as leader'])
            ->map(fn (object $row): array => $this->auctionRow($row))
            ->all();
    }

    /** @return array<string, mixed> */
    public function createAuction(string $productId, int $startMinor, string $startsAt, string $endsAt): array
    {
        $start = $this->moment($startsAt);
        $end = $this->moment($endsAt);
        if ($end <= $start || $startMinor < 0 || ! $this->productExists($productId)) {
            throw ApiException::auctionClosed();
        }
        $id = (string) Str::uuid7();
        DB::table('auctions')->insert([
            'id' => $id,
            'product_id' => $productId,
            'start_minor' => $startMinor,
            'current_minor' => $startMinor,
            'starts_at' => $start,
            'ends_at' => $end,
            'created_at' => now(),
        ]);

        return $this->auction($id);
    }

    /** @return array<string, mixed> */
    public function scheduleAuction(string $auctionId, string $startsAt, string $endsAt): array
    {
        $auction = $this->openAuction($auctionId);
        if ($auction->leader_id !== null) {
            throw ApiException::auctionClosed();
        }
        $start = $this->moment($startsAt);
        $end = $this->moment($endsAt);
        if ($end <= $start) {
            throw ApiException::auctionClosed();
        }
        DB::table('auctions')->where('id', $auctionId)->update([
            'starts_at' => $start,
            'ends_at' => $end,
        ]);

        return $this->auction($auctionId);
    }

    /** @return array<string, mixed> */
    public function endAuction(string $auctionId): array
    {
        $auction = $this->openAuction($auctionId);
        $now = now();
        DB::table('auctions')->where('id', $auctionId)->update([
            'ended_at' => $now,
            'ends_at' => $auction->ends_at > $now ? $now : $auction->ends_at,
        ]);
        $intent = is_string($auction->leader_payment_intent_id) ? $auction->leader_payment_intent_id : '';
        if ($intent !== '') {
            try {
                $this->gateway->cancel($intent, 'auction-end:'.$auctionId);
            } catch (PaymentRejected|PaymentGatewayUnavailable) {
                // The auction is closed. A leftover hold expires with the intent.
            }
        }

        return $this->auction($auctionId);
    }

    /** @return list<array<string, mixed>> */
    public function products(?string $query): array
    {
        $like = $this->like($query);

        return DB::table('products')
            ->when($like !== null, fn ($rows) => $rows->where('title', 'ilike', $like))
            ->orderByDesc('updated_at')
            ->limit(30)
            ->get(['id', 'title', 'brand', 'price_minor', 'currency', 'stock', 'listing_status', 'seller_id'])
            ->map(fn (object $row): array => [
                'id' => $row->id,
                'title' => $row->title,
                'brand' => $row->brand,
                'price' => Currency::money((int) $row->price_minor, (string) $row->currency),
                'stock' => (int) $row->stock,
                'listing_status' => $row->listing_status,
                'seller_id' => $row->seller_id,
            ])
            ->all();
    }

    /** @param  array<string, mixed>  $fields */
    public function createProduct(array $fields): array
    {
        $price = (int) $fields['price_minor'];
        $id = (string) Str::uuid7();
        $status = ListingStatus::tryFrom((string) ($fields['listing_status'] ?? 'active')) ?? ListingStatus::Active;
        (new Product)->forceFill([
            'id' => $id,
            'title' => $fields['title'],
            'brand' => $fields['brand'],
            'image_url' => $fields['image_url'],
            'price_minor' => $price,
            'squad_price_minor' => intdiv($price * 70, 100),
            'slug' => 'staff-'.substr(str_replace('-', '', $id), 0, 16),
            'currency' => 'USD',
            'stock' => (int) $fields['stock'],
            'listing_status' => $status,
        ])->save();

        return [
            'id' => $id,
            'title' => $fields['title'],
            'brand' => $fields['brand'],
            'price' => Currency::money($price, 'USD'),
            'stock' => (int) $fields['stock'],
            'listing_status' => $status->value,
            'seller_id' => null,
        ];
    }

    /** @return array<string, mixed> */
    public function updateProduct(string $productId, string $listingStatus, ?int $stock): array
    {
        $status = ListingStatus::tryFrom($listingStatus);
        $product = Str::isUuid($productId) ? Product::query()->find($productId) : null;
        if ($product === null || $status === null) {
            throw ApiException::productNotFound();
        }
        $product->forceFill(['listing_status' => $status] + ($stock === null ? [] : ['stock' => $stock]))->save();

        return [
            'id' => $product->id,
            'title' => $product->title,
            'listing_status' => $status->value,
            'stock' => (int) $product->stock,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function sellers(): array
    {
        return DB::table('sellers as s')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->orderBy('s.updated_at')
            ->limit(30)
            ->get(['s.user_id', 'u.username', 's.legal_name', 's.country', 's.status', 's.charges_enabled'])
            ->map(fn (object $row): array => [
                'user_id' => $row->user_id,
                'username' => $row->username,
                'legal_name' => $row->legal_name,
                'country' => $row->country,
                'status' => $row->status,
                'charges_enabled' => (bool) $row->charges_enabled,
            ])
            ->all();
    }

    /** @return array<string, mixed> */
    public function setSellerStatus(string $userId, SellerStatus $status): array
    {
        $seller = Str::isUuid($userId) ? Seller::query()->find($userId) : null;
        if ($seller === null) {
            throw ApiException::profileNotFound();
        }
        $verified = $status === SellerStatus::Verified;
        $seller->transitionTo($status, [
            'charges_enabled' => $verified,
            'payouts_enabled' => $verified,
        ]);

        return [
            'user_id' => $seller->user_id,
            'status' => $status->value,
            'charges_enabled' => $verified,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function posts(): array
    {
        return DB::table('feed_posts as p')
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->whereNull('p.deleted_at')
            ->orderByDesc('p.created_at')
            ->limit(30)
            ->get(['p.id', 'u.username', 'p.caption', 'p.hidden_at', 'p.audience'])
            ->map(fn (object $row): array => [
                'id' => $row->id,
                'username' => $row->username,
                'caption' => $row->caption,
                'audience' => $row->audience,
                'hidden' => $row->hidden_at !== null,
            ])
            ->all();
    }

    public function hidePost(User $admin, string $postId, bool $hidden): void
    {
        $updated = $this->postQuery($postId)->update([
            'hidden_at' => $hidden ? now() : null,
            'hidden_by' => $hidden ? $admin->id : null,
        ]);
        if ($updated !== 1) {
            throw ApiException::postNotFound();
        }
        $this->forgetFeed();
    }

    public function removePost(string $postId): void
    {
        $post = $this->postQuery($postId)->first();
        if ($post === null) {
            throw ApiException::postNotFound();
        }
        FeedPost::query()->whereKey($postId)->first()?->delete();
        $this->forgetFeed();
    }

    /** @return list<array<string, mixed>> */
    public function comments(): array
    {
        return DB::table('feed_post_comments as c')
            ->join('users as u', 'u.id', '=', 'c.user_id')
            ->whereNull('c.deleted_at')
            ->orderByDesc('c.created_at')
            ->limit(30)
            ->get(['c.id', 'c.post_id', 'u.username', 'c.body', 'c.hidden_at'])
            ->map(fn (object $row): array => [
                'id' => $row->id,
                'post_id' => $row->post_id,
                'username' => $row->username,
                'body' => $row->body,
                'hidden' => $row->hidden_at !== null,
            ])
            ->all();
    }

    public function hideComment(User $admin, string $commentId, bool $hidden): void
    {
        if (! Str::isUuid($commentId) || ! DB::table('feed_post_comments')->where('id', $commentId)->whereNull('deleted_at')->exists()) {
            throw ApiException::commentNotAllowed('missing');
        }
        DB::table('feed_post_comments')
            ->where(fn ($query) => $query->where('id', $commentId)->orWhere('parent_id', $commentId))
            ->whereNull('deleted_at')
            ->update([
                'hidden_at' => $hidden ? now() : null,
                'hidden_by' => $hidden ? $admin->id : null,
            ]);
    }

    public function removeComment(string $commentId): void
    {
        if (! Str::isUuid($commentId)) {
            throw ApiException::commentNotAllowed('missing');
        }
        $ids = DB::table('feed_post_comments')
            ->where(fn ($query) => $query->where('id', $commentId)->orWhere('parent_id', $commentId))
            ->whereNull('deleted_at')
            ->pluck('id');
        if ($ids->isEmpty()) {
            throw ApiException::commentNotAllowed('missing');
        }
        FeedPostComment::query()->whereIn('id', $ids)->get()->each->delete();
    }

    /** @return list<array<string, mixed>> */
    public function users(?string $query): array
    {
        $like = $this->like($query);

        return DB::table('users')
            ->whereNull('anonymized_at')
            ->when($like !== null, fn ($rows) => $rows->where('username', 'ilike', $like))
            ->orderBy('username')
            ->limit(30)
            ->get(['id', 'username', 'display_name', 'risk_status', 'role'])
            ->map(fn (object $row): array => [
                'id' => $row->id,
                'username' => $row->username,
                'display_name' => $row->display_name,
                'risk_status' => $row->risk_status,
                'role' => $row->role,
            ])
            ->all();
    }

    public function setBan(User $admin, string $userId, bool $banned): void
    {
        $user = Str::isUuid($userId) ? User::query()->find($userId) : null;
        if ($user === null || $user->isAnonymized()) {
            throw ApiException::profileNotFound();
        }
        if ($user->role === UserRole::Admin || $user->is($admin)) {
            throw ApiException::adminRequired();
        }
        $user->forceFill(['risk_status' => $banned ? RiskStatus::Banned : RiskStatus::Ok])->save();
        if ($banned) {
            DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->delete();
        }
    }

    /** @return array<string, mixed> */
    private function auction(string $id): array
    {
        $row = DB::table('auctions as a')
            ->join('products as p', 'p.id', '=', 'a.product_id')
            ->leftJoin('users as u', 'u.id', '=', 'a.leader_id')
            ->where('a.id', $id)
            ->first(['a.id', 'a.product_id', 'p.title', 'p.currency', 'a.start_minor', 'a.current_minor', 'a.starts_at', 'a.ends_at', 'a.ended_at', 'u.username as leader']);

        return $this->auctionRow($row);
    }

    /** @return array<string, mixed> */
    private function auctionRow(object $row): array
    {
        return [
            'id' => $row->id,
            'product_id' => $row->product_id,
            'title' => $row->title,
            'start' => Currency::money((int) $row->start_minor, (string) $row->currency),
            'current' => Currency::money((int) $row->current_minor, (string) $row->currency),
            'starts_at' => (string) $row->starts_at,
            'ends_at' => (string) $row->ends_at,
            'ended' => $row->ended_at !== null,
            'leader' => $row->leader,
        ];
    }

    private function openAuction(string $auctionId): object
    {
        $auction = Str::isUuid($auctionId) ? DB::table('auctions')->where('id', $auctionId)->first() : null;
        if ($auction === null || $auction->ended_at !== null) {
            throw ApiException::auctionClosed();
        }

        return $auction;
    }

    private function productExists(string $productId): bool
    {
        return Str::isUuid($productId) && DB::table('products')->where('id', $productId)->exists();
    }

    private function moment(string $value): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            throw ApiException::auctionClosed();
        }
    }

    /** @return Builder */
    private function postQuery(string $postId)
    {
        return DB::table('feed_posts')->where('id', $postId)->whereNull('deleted_at')->when(
            ! Str::isUuid($postId),
            fn ($query) => $query->whereRaw('1 = 0'),
        );
    }

    private function like(?string $query): ?string
    {
        $query = trim((string) $query);
        if ($query === '') {
            return null;
        }

        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query).'%';
    }

    private function forgetFeed(): void
    {
        Cache::forget('hashtags:trending:v1');
        Cache::forget('feed:fy:global:v1');
    }
}

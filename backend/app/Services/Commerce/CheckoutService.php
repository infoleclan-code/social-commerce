<?php

declare(strict_types=1);

namespace App\Services\Commerce;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\Payments\PaymentStep;
use App\Support\Currency;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Server-priced checkout with squad discounts, flash stock, coupons and creator/referral commissions.
 *
 * The client never sends a price; it may send the total it was shown (expected_total_minor) and gets
 * 409 PRICE_CHANGED instead of a surprise. Prices always come from the database row locked for this
 * purchase, never from a cache. Discounts are max(flash drop, squad) + one coupon, capped at
 * commerce.max_discount_bps. Commissions are fixed at checkout (within commerce.max_commission_bps)
 * but posted to the ledger only when the card is captured.
 */
final class CheckoutService
{
    public function __construct(private readonly Purchases $purchases) {}

    /** @return array<string, mixed> */
    public function checkout(
        User $buyer,
        string $productId,
        ?string $postId,
        ?string $squadId,
        ?string $couponId,
        int $quantity,
        string $idempotencyKey,
        ?string $referralToken = null,
        bool $sharePublicly = false,
        ?int $expectedTotalMinor = null,
        ?string $addressId = null,
    ): array {
        $existing = $this->purchases->existing($buyer, $idempotencyKey);
        if ($existing !== null && (string) $existing->product_id !== $productId) {
            throw ApiException::idempotencyConflict();
        }

        [$order, $step, $replayed] = $this->purchases->place(
            $buyer,
            $idempotencyKey,
            'checkout',
            fn (): array => $this->reserve($buyer, $productId, $postId, $squadId, $couponId, $quantity, $referralToken, $sharePublicly, $expectedTotalMinor, $addressId),
        );
        if ((string) $order->product_id !== $productId) {
            throw ApiException::idempotencyConflict();
        }

        return $this->orderPayload($order, $step, $replayed);
    }

    /** @return array<string, mixed> */
    private function reserve(User $buyer, string $productId, ?string $postId, ?string $squadId, ?string $couponId, int $quantity, ?string $referralToken, bool $sharePublicly, ?int $expectedTotalMinor, ?string $addressId = null): array
    {
        $product = DB::table('products')->where('id', $productId)->lockForUpdate()->first();
        if ($product === null || $product->listing_status !== 'active' || (int) $product->stock < $quantity) {
            throw ApiException::outOfStock();
        }

        $drop = DB::table('flash_drops')
            ->where('product_id', $productId)
            ->where('stock_remaining', '>=', $quantity)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now())
            ->orderBy('ends_at')
            ->lockForUpdate()
            ->first();

        $squad = null;
        $squadBps = 0;
        if ($squadId !== null) {
            $squad = DB::table('squads')->where('id', $squadId)->where('product_id', $productId)->first();
            if ($squad === null || now()->greaterThan($squad->expires_at)
                || ! DB::table('squad_members')->where('squad_id', $squadId)->where('user_id', $buyer->id)->exists()) {
                throw ApiException::squadClosed();
            }
            $members = (int) DB::table('squad_members')->where('squad_id', $squadId)->count();
            $squadBps = min(2500, $members * (int) $squad->discount_bps_per_member);
        }

        $coupon = null;
        if ($couponId !== null) {
            $coupon = DB::table('coupons')
                ->where('id', $couponId)
                ->where('user_id', $buyer->id)
                ->whereNull('redeemed_at')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();
            if ($coupon === null) {
                throw ApiException::couponInvalid();
            }
        }

        $discountBps = DiscountPolicy::combine((int) ($drop->discount_bps ?? 0), $squadBps, (int) ($coupon->discount_bps ?? 0));
        $unit = intdiv((int) $product->price_minor * (10000 - $discountBps), 10000);
        if ($squad !== null) {
            $unit = max((int) $squad->floor_price_minor, $unit);
        }
        $destination = $this->destination($buyer, $addressId);
        $charge = LandedCost::charge($product, $destination, $unit, $quantity);
        $total = $charge['total_minor'];
        if ($expectedTotalMinor !== null && $expectedTotalMinor !== $total) {
            throw ApiException::priceChanged($total, (string) $product->currency);
        }

        if (DB::update('UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?', [$quantity, $productId, $quantity]) !== 1) {
            throw ApiException::outOfStock();
        }
        if ($drop !== null && DB::update('UPDATE flash_drops SET stock_remaining = stock_remaining - ? WHERE id = ? AND stock_remaining >= ?', [$quantity, $drop->id, $quantity]) !== 1) {
            throw ApiException::outOfStock();
        }
        if ($coupon !== null) {
            DB::table('coupons')->where('id', $coupon->id)->whereNull('redeemed_at')->update(['redeemed_at' => now()]);
        }

        $merchandise = $unit * $quantity;
        [$creatorId, $commission] = $this->creatorCommission($buyer, $postId, $productId, $merchandise);
        $referralLinkId = $this->referralLink($buyer, $referralToken, $productId, $creatorId);

        return [
            'product_id' => $productId,
            'post_id' => $postId,
            'creator_id' => $creatorId,
            'creator_commission_minor' => $commission,
            'referral_link_id' => $referralLinkId,
            'flash_drop_id' => $drop?->id,
            'squad_id' => $squad?->id,
            'coupon_id' => $coupon?->id,
            'quantity' => $quantity,
            'unit_price_minor' => $unit,
            'discount_bps' => $discountBps,
            'total_minor' => $total,
            'currency' => $product->currency,
            'share_publicly' => $sharePublicly,
            'shipping_address_id' => $addressId,
        ];
    }

    /** The country the parcel is priced for: a saved address when the buyer picked one, otherwise their profile. */
    private function destination(User $buyer, ?string $addressId): ?string
    {
        if ($addressId === null) {
            return $buyer->country_code;
        }
        $address = DB::table('shipping_addresses')->where('id', $addressId)->where('user_id', $buyer->id)->first();
        if ($address === null) {
            throw ApiException::addressNotFound();
        }

        return (string) $address->country;
    }

    /** @return array{0: string|null, 1: int} */
    private function creatorCommission(User $buyer, ?string $postId, string $productId, int $total): array
    {
        if ($postId === null) {
            return [null, 0];
        }
        $tag = DB::table('reel_product_tags as t')
            ->join('feed_posts as p', 'p.id', '=', 't.post_id')
            ->where('t.post_id', $postId)
            ->where('t.product_id', $productId)
            ->whereNull('p.deleted_at')
            ->first(['t.commission_bps', 'p.user_id']);
        if ($tag === null || (string) $tag->user_id === (string) $buyer->id) {
            return [null, 0];
        }
        $bps = min((int) $tag->commission_bps, (int) config('commerce.max_commission_bps'));

        return [(string) $tag->user_id, intdiv($total * max(0, $bps), 10000)];
    }

    private function referralLink(User $buyer, ?string $token, string $productId, ?string $creatorId): ?string
    {
        if ($token === null || $token === '') {
            return null;
        }
        $link = DB::table('referral_links')->where('token', $token)->first(['id', 'user_id', 'product_id']);
        if ($link === null || (string) $link->product_id !== $productId
            || (string) $link->user_id === (string) $buyer->id || (string) $link->user_id === (string) $creatorId) {
            return null;
        }

        return (string) $link->id;
    }

    /** @return array<string, mixed> */
    public function openSquad(User $host, string $productId, ?string $postId): array
    {
        $product = DB::table('products')->where('id', $productId)->where('listing_status', 'active')->first();
        if ($product === null) {
            throw ApiException::outOfStock();
        }
        $id = (string) Str::uuid7();
        DB::transaction(function () use ($id, $host, $product, $productId, $postId): void {
            DB::table('squads')->insert([
                'id' => $id,
                'product_id' => $productId,
                'host_id' => $host->id,
                'post_id' => $postId,
                'discount_bps_per_member' => 500,
                'max_members' => 5,
                'floor_price_minor' => intdiv((int) $product->price_minor * 60, 100),
                'expires_at' => now()->addHours(24),
                'created_at' => now(),
            ]);
            DB::table('squad_members')->insert([
                'squad_id' => $id,
                'user_id' => $host->id,
                'joined_at' => now(),
            ]);
        });

        return $this->squadPayload($id);
    }

    /** @return array<string, mixed> */
    public function joinSquad(User $user, string $squadId): array
    {
        $squad = DB::table('squads')->where('id', $squadId)->first();
        if ($squad === null || now()->greaterThan($squad->expires_at)) {
            throw ApiException::squadClosed();
        }
        try {
            DB::transaction(fn () => DB::table('squad_members')->insertOrIgnore([
                'squad_id' => $squadId,
                'user_id' => $user->id,
                'joined_at' => now(),
            ]));
        } catch (QueryException $full) {
            if (($full->errorInfo[0] ?? '') === '23514') {
                throw ApiException::squadClosed();
            }
            throw $full;
        }

        return $this->squadPayload($squadId);
    }

    /** @return array<string, mixed> */
    public function orderPayload(object $order, PaymentStep $step, bool $replayed): array
    {
        return [
            'id' => $order->id,
            'status' => $order->status,
            'payment_status' => $step->paymentStatus,
            'next_action' => $step->nextAction,
            'failure_code' => $step->failureCode,
            'undo_until' => $this->undoUntil((string) $order->id, $step->paymentStatus),
            'replayed' => $replayed,
            'quantity' => (int) $order->quantity,
            'discount_bps' => (int) $order->discount_bps,
            'share_publicly' => (bool) $order->share_publicly,
            'withdrawal_deadline' => $order->delivered_at === null ? null : (string) now()->parse($order->delivered_at)->addDays(14),
            'total' => Currency::money((int) $order->total_minor, (string) $order->currency),
        ];
    }

    private function undoUntil(string $orderId, string $paymentStatus): ?string
    {
        if (! in_array($paymentStatus, ['pending', 'requires_action', 'authorized'], true)) {
            return null;
        }
        $until = DB::table('payment_authorizations')->where('order_id', $orderId)->orderByDesc('created_at')->value('capture_after');

        return $until === null ? null : (string) $until;
    }

    /** @return array<string, mixed> */
    private function squadPayload(string $squadId): array
    {
        $squad = DB::table('squads')->where('id', $squadId)->first();
        $members = (int) DB::table('squad_members')->where('squad_id', $squadId)->count();

        return [
            'id' => $squad->id,
            'product_id' => $squad->product_id,
            'post_id' => $squad->post_id,
            'members' => $members,
            'max_members' => (int) $squad->max_members,
            'discount_bps_per_member' => (int) $squad->discount_bps_per_member,
            'expires_at' => (string) $squad->expires_at,
        ];
    }
}

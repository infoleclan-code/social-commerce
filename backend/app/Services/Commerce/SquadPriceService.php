<?php

declare(strict_types=1);

namespace App\Services\Commerce;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\Payments\PaymentStep;
use App\Support\Currency;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Squad price: every member's card is authorized for the solo price and held until the squad closes.
 * When a second member joins, every open member order is repriced to the squad price and captured
 * (a partial capture: the rest of the hold is released by Stripe). A squad that expires alone is
 * captured at the solo price. Capture runs in payments:capture-due; nothing is charged in this class.
 *
 * Capacity is enforced twice: here under the squad row lock, and by a database trigger.
 */
final class SquadPriceService
{
    public function __construct(private readonly Purchases $purchases) {}

    /** @return array<string, mixed> */
    public function authorize(User $buyer, string $productId, ?string $postId, string $idempotencyKey): array
    {
        $expires = now()->addHours(24);
        [$order, $step, $replayed] = $this->purchases->place($buyer, $idempotencyKey, 'squad', function () use ($buyer, $productId, $postId, $expires): array {
            $product = $this->reserveUnit($productId);
            $squadId = (string) Str::uuid7();
            DB::table('squads')->insert([
                'id' => $squadId,
                'product_id' => $productId,
                'host_id' => $buyer->id,
                'post_id' => $postId,
                'discount_bps_per_member' => 0,
                'max_members' => 5,
                'floor_price_minor' => (int) $product->squad_price_minor,
                'expires_at' => $expires,
                'created_at' => now(),
            ]);
            self::claimSeat($squadId, (string) $buyer->id);

            return $this->soloOrder($product, $postId, $squadId);
        }, captureDelaySeconds: (int) now()->diffInSeconds($expires));

        return $this->payload($order, $step, $replayed);
    }

    /** @return array<string, mixed> */
    public function join(User $buyer, string $squadId, string $idempotencyKey): array
    {
        $squad = Str::isUuid($squadId) ? DB::table('squads')->where('id', $squadId)->first() : null;
        if ($squad === null) {
            throw ApiException::squadNotFound();
        }
        [$order, $step, $replayed] = $this->purchases->place($buyer, $idempotencyKey, 'squad', function () use ($buyer, $squadId): array {
            $squad = DB::table('squads')->where('id', $squadId)->lockForUpdate()->first();
            if ($squad === null || now()->greaterThanOrEqualTo($squad->expires_at)) {
                throw ApiException::squadClosed();
            }
            $members = self::seatCount($squadId);
            $already = self::buyerHasSeat($squadId, (string) $buyer->id);
            if ($already || $members >= (int) $squad->max_members) {
                throw ApiException::squadClosed();
            }
            $product = $this->reserveUnit((string) $squad->product_id);
            self::claimSeat($squadId, (string) $buyer->id);

            return $this->soloOrder($product, $squad->post_id, $squadId);
        }, captureDelaySeconds: max(0, (int) now()->diffInSeconds($squad->expires_at, false)));

        if (! $replayed) {
            $this->settle($squadId);
        }

        return $this->payload(DB::table('orders')->where('id', $order->id)->first(), $step, $replayed);
    }

    /**
     * Reprices open member orders once the squad unlocks (two or more members) and schedules their
     * capture now. Idempotent; members only.
     *
     * @return array<string, mixed>
     */
    public function settle(string $squadId, ?User $member = null): array
    {
        if ($member !== null && (! Str::isUuid($squadId) || ! self::callerHasPaidSeat($squadId, $member))) {
            throw ApiException::squadNotFound();
        }

        return DB::transaction(function () use ($squadId): array {
            $squad = DB::table('squads')->where('id', $squadId)->lockForUpdate()->first();
            if ($squad === null) {
                throw ApiException::squadNotFound();
            }
            $product = DB::table('products')->where('id', $squad->product_id)->first();
            $members = self::paidMemberCount($squadId);
            $unlocked = $members >= 2;
            if ($unlocked) {
                $price = (int) $squad->floor_price_minor;
                $open = DB::table('orders as o')
                    ->join('payment_authorizations as a', 'a.order_id', '=', 'o.id')
                    ->where('o.squad_id', $squadId)
                    ->where('o.purpose', 'squad')
                    ->where('o.status', 'processing')
                    ->whereIn('a.status', ['pending', 'requires_action', 'authorized'])
                    ->lockForUpdate()
                    ->get(['o.id', 'o.unit_price_minor', 'a.id as auth_id', 'a.authorized_minor']);
                foreach ($open as $row) {
                    $capture = min($price, (int) $row->authorized_minor);
                    $discount = (int) $row->unit_price_minor > 0 ? intdiv(((int) $row->unit_price_minor - $capture) * 10000, (int) $row->unit_price_minor) : 0;
                    DB::table('orders')->where('id', $row->id)->update([
                        'unit_price_minor' => $capture,
                        'total_minor' => $capture,
                        'discount_bps' => max(0, min(8000, $discount)),
                    ]);
                    DB::table('payment_authorizations')->where('id', $row->auth_id)->update(['capture_after' => now()]);
                }
            }

            return [
                'settled' => $unlocked || now()->greaterThanOrEqualTo($squad->expires_at),
                'members' => $members,
                'squad_id' => $squadId,
                'price_kind' => $unlocked ? 'squad' : 'solo',
                'captured' => Currency::money($unlocked ? (int) $squad->floor_price_minor : (int) $product->price_minor, (string) $product->currency),
            ];
        });
    }

    /** Buyers with a non-cancelled squad order. An unpaid join does not count. */
    public static function paidMemberCount(string $squadId): int
    {
        return (int) self::qualifyingOrders($squadId)->where('purpose', 'squad')->distinct()->count('buyer_id');
    }

    /** Paid or authorized orders for this squad, whatever the checkout purpose. Unpaid joins are absent. */
    public static function seatCount(string $squadId): int
    {
        return (int) self::qualifyingOrders($squadId)->distinct()->count('buyer_id');
    }

    public static function buyerHasSeat(string $squadId, string $userId): bool
    {
        return self::qualifyingOrders($squadId)->where('buyer_id', $userId)->exists();
    }

    /** A seat exists only beside an order. The capacity trigger still rejects a squad that is already full. */
    public static function claimSeat(string $squadId, string $userId): void
    {
        try {
            DB::table('squad_members')->insertOrIgnore([
                'squad_id' => $squadId,
                'user_id' => $userId,
                'joined_at' => now(),
            ]);
        } catch (QueryException $full) {
            if (($full->errorInfo[0] ?? '') === '23514') {
                throw ApiException::squadClosed();
            }
            throw $full;
        }
    }

    /** Drops the seat once the buyer has no paid or authorized order left on the squad. */
    public static function releaseSeat(string $squadId, string $userId): void
    {
        if (self::buyerHasSeat($squadId, $userId)) {
            return;
        }
        DB::table('squad_members')->where('squad_id', $squadId)->where('user_id', $userId)->delete();
    }

    private static function qualifyingOrders(string $squadId): Builder
    {
        return DB::table('orders')
            ->where('squad_id', $squadId)
            ->where('status', '<>', 'cancelled')
            ->whereIn('payment_status', ['pending', 'requires_action', 'authorized', 'captured', 'partially_refunded', 'disputed']);
    }

    private static function callerHasPaidSeat(string $squadId, User $member): bool
    {
        return DB::table('orders')
            ->where('squad_id', $squadId)
            ->where('buyer_id', $member->id)
            ->where('purpose', 'squad')
            ->where('status', '<>', 'cancelled')
            ->exists();
    }

    private function reserveUnit(string $productId): object
    {
        $product = DB::table('products')->where('id', $productId)->lockForUpdate()->first();
        if ($product === null || $product->listing_status !== 'active' || (int) $product->stock < 1) {
            throw ApiException::outOfStock();
        }
        if (DB::update('UPDATE products SET stock = stock - 1 WHERE id = ? AND stock >= 1', [$productId]) !== 1) {
            throw ApiException::outOfStock();
        }

        return $product;
    }

    /** @return array<string, mixed> */
    private function soloOrder(object $product, ?string $postId, string $squadId): array
    {
        return [
            'product_id' => $product->id,
            'post_id' => $postId,
            'squad_id' => $squadId,
            'quantity' => 1,
            'unit_price_minor' => (int) $product->price_minor,
            'discount_bps' => 0,
            'total_minor' => (int) $product->price_minor,
            'currency' => $product->currency,
        ];
    }

    /** @return array<string, mixed> */
    private function payload(object $order, PaymentStep $step, bool $replayed): array
    {
        $auth = DB::table('payment_authorizations')->where('order_id', $order->id)->orderByDesc('created_at')->first();
        $product = DB::table('products')->where('id', $order->product_id)->first();
        $members = $order->squad_id === null ? 1 : self::seatCount((string) $order->squad_id);
        $squad = $order->squad_id === null ? null : DB::table('squads')->where('id', $order->squad_id)->first();

        return [
            'authorization_id' => $auth?->id,
            'squad_id' => $order->squad_id,
            'order_id' => $order->id,
            'status' => $auth->status ?? 'pending',
            'payment_status' => $step->paymentStatus,
            'next_action' => $step->nextAction,
            'failure_code' => $step->failureCode,
            'replayed' => $replayed,
            'members' => $members,
            'expires_at' => $squad === null ? null : (string) $squad->expires_at,
            'authorized' => Currency::money((int) ($auth->authorized_minor ?? $order->total_minor), (string) $order->currency),
            'solo' => Currency::money((int) $product->price_minor, (string) $product->currency),
            'squad' => Currency::money((int) $product->squad_price_minor, (string) $product->currency),
        ];
    }
}

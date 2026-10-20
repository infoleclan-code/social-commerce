<?php

declare(strict_types=1);

namespace App\Services\Commerce;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Price protection: if the catalog price of a captured order's product drops within 14 days, the
 * buyer is credited the difference (once per level, never twice).
 *
 * Abuse rules
 *  - only a price that stayed on sale for commerce.price_protection.min_hold_hours counts, measured
 *    from product_price_history (a trigger records every change): a seller, or an attacker with a
 *    seller account, cannot flash a 1-cent price for a second to drain the platform;
 *  - the same currency only (a listing switched from USD to JPY is not a "drop");
 *  - at most commerce.price_protection.max_payout_bps of the paid unit price;
 *  - only orders that are captured, not refunded and not disputed; a refund later takes the credit
 *    back in proportion (LedgerService::settlePriceProtection).
 * Credits are cumulative and keyed (price_protection:{order}:{total}), so concurrent sweeps, retries
 * and a sweep racing a refund cannot pay twice.
 */
final class PriceGuard
{
    public function __construct(private readonly LedgerService $ledger) {}

    public function cover(string $orderId): void
    {
        $order = DB::table('orders')->where('id', $orderId)->first();
        if ($order === null || $order->status !== 'paid' || $order->payment_status !== 'captured') {
            return;
        }
        $from = $order->captured_at ?? $order->created_at;
        DB::table('price_protections')->insertOrIgnore([
            'order_id' => $order->id,
            'paid_unit_minor' => (int) $order->unit_price_minor,
            'quantity' => (int) $order->quantity,
            'currency' => $order->currency,
            'refunded_minor' => 0,
            'protected_until' => Carbon::parse($from)->addDays((int) config('commerce.price_protection.window_days', 14)),
            'created_at' => now(),
        ]);
    }

    /** Settles every open protection (optionally for one product). Returns how many orders were credited. */
    public function sweep(?string $productId = null, int $batch = 500): int
    {
        $credited = 0;
        $after = '00000000-0000-0000-0000-000000000000';
        do {
            $ids = DB::table('price_protections as p')
                ->join('orders as o', 'o.id', '=', 'p.order_id')
                ->where('p.protected_until', '>', now())
                ->where('o.status', 'paid')
                ->where('o.payment_status', 'captured')
                ->where('p.order_id', '>', $after)
                ->when($productId !== null, fn ($query) => $query->where('o.product_id', $productId))
                ->orderBy('p.order_id')
                ->limit($batch)
                ->pluck('p.order_id');
            foreach ($ids as $orderId) {
                if ($this->settle((string) $orderId) > 0) {
                    $credited++;
                }
                $after = (string) $orderId;
            }
        } while ($ids->count() === $batch);

        return $credited;
    }

    /** @return array<string, mixed> */
    public function status(string $orderId, string $buyerId): array
    {
        $row = DB::table('price_protections as p')
            ->join('orders as o', 'o.id', '=', 'p.order_id')
            ->where('p.order_id', $orderId)
            ->where('o.buyer_id', $buyerId)
            ->first(['p.refunded_minor', 'p.protected_until', 'p.currency', 'p.paid_unit_minor']);
        if ($row === null) {
            return ['protected' => false, 'refunded_minor' => 0];
        }

        return [
            'protected' => now()->lessThan($row->protected_until),
            'protected_until' => (string) $row->protected_until,
            'refunded_minor' => (int) $row->refunded_minor,
            'currency' => $row->currency,
            'paid_unit_minor' => (int) $row->paid_unit_minor,
        ];
    }

    /** Credits whatever is still owed on one order; returns the newly credited amount. */
    public function settle(string $orderId): int
    {
        return (int) DB::transaction(function () use ($orderId): int {
            $guard = DB::table('price_protections')->where('order_id', $orderId)->lockForUpdate()->first();
            if ($guard === null || now()->greaterThanOrEqualTo($guard->protected_until)) {
                return 0;
            }
            $order = DB::table('orders')->where('id', $orderId)->first();
            if ($order === null || $order->status !== 'paid' || $order->payment_status !== 'captured' || (int) $order->amount_refunded_minor > 0) {
                return 0;
            }
            $lowest = $this->lowestHeldPrice((string) $order->product_id, (string) $guard->currency, (string) ($order->captured_at ?? $order->created_at));
            if ($lowest === null) {
                return 0;
            }
            $paidUnit = (int) $guard->paid_unit_minor;
            $cap = intdiv($paidUnit * (int) config('commerce.price_protection.max_payout_bps', 5000), 10000);
            $perUnit = min($paidUnit - $lowest, $cap);
            if ($perUnit < 1) {
                return 0;
            }
            $target = $perUnit * (int) $guard->quantity;
            $before = (int) $guard->refunded_minor;
            if ($target <= $before) {
                DB::table('price_protections')->where('order_id', $orderId)->update(['last_checked_at' => now()]);

                return 0;
            }
            $this->ledger->creditPriceProtection($orderId, (string) $order->buyer_id, $target, (string) $guard->currency);
            DB::table('price_protections')->where('order_id', $orderId)->update([
                'refunded_minor' => $target,
                'last_checked_at' => now(),
            ]);

            return $target - $before;
        });
    }

    /**
     * The lowest price, in this currency, that stayed on sale for the minimum hold at some point after
     * the purchase (the current price counts once it has held long enough).
     */
    private function lowestHeldPrice(string $productId, string $currency, string $since): ?int
    {
        $hours = max(0, (int) config('commerce.price_protection.min_hold_hours', 24));
        $row = DB::selectOne(
            'SELECT MIN(price_minor) AS lowest
               FROM product_price_history
              WHERE product_id = ?
                AND currency = ?
                AND COALESCE(valid_to, clock_timestamp()) > ?::timestamptz
                AND COALESCE(valid_to, clock_timestamp()) - GREATEST(valid_from, ?::timestamptz) >= make_interval(hours => ?)',
            [$productId, $currency, $since, $since, $hours],
        );

        return $row?->lowest === null ? null : (int) $row->lowest;
    }
}

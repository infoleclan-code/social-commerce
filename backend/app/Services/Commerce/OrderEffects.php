<?php

declare(strict_types=1);

namespace App\Services\Commerce;

use App\Models\User;
use App\Services\Feed\TasteService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What happens to the rest of the system when an order's money moves. Called by OrderPayments inside
 * the transaction that changes the payment status, so each effect happens exactly once and together
 * with the status change (or not at all).
 *
 *  - captured: the sale is posted, creator and referral commissions are held as pending, the price
 *    guard starts, a public purchase appears in the activity feed, gifts and blind pulls become paid;
 *  - released (declined, voided, never authorized): stock, flash-drop units and the coupon come back,
 *    the gift or blind pull is closed, a failed squad member leaves the squad;
 *  - fully refunded: the item is restocked (when it comes back) and the gift is closed. Commission and
 *    price-protection reversals are posted by OrderPayments through the ledger.
 */
final class OrderEffects
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly ShareEarnService $shares,
        private readonly PriceGuard $prices,
        private readonly TasteService $taste,
    ) {}

    public function onCaptured(object $order, int $amountReceived): void
    {
        $orderId = (string) $order->id;
        $currency = (string) $order->currency;
        $this->ledger->recordSale($orderId, $amountReceived, $currency);

        if ($order->creator_id !== null && (int) $order->creator_commission_minor > 0) {
            $this->ledger->pendCommission((string) $order->creator_id, $orderId, (int) $order->creator_commission_minor, $currency);
        }
        $this->shares->earnForOrder($order);

        if (in_array($order->purpose, ['checkout', 'one_tap', 'squad', 'gift'], true)) {
            $this->prices->cover($orderId);
        }
        if ((bool) $order->share_publicly) {
            DB::table('activity_events')->insert([
                'id' => (string) Str::uuid7(),
                'actor_id' => $order->buyer_id,
                'kind' => 'purchase',
                'product_id' => $order->product_id,
                'post_id' => $order->post_id,
                'created_at' => now(),
            ]);
        }
        DB::table('gifts')->where('order_id', $orderId)->where('status', 'pending')->update(['status' => 'paid']);
        DB::table('blind_pulls')->where('order_id', $orderId)->where('status', 'pending')->update(['status' => 'paid']);
        DB::table('night_holds')->where('order_id', $orderId)->where('status', 'held')->update(['status' => 'released']);

        if ($order->post_id !== null) {
            $buyer = User::query()->find($order->buyer_id);
            if ($buyer !== null) {
                $this->taste->observe($buyer, (string) $order->post_id, 'order');
            }
        }
    }

    public function onReleased(object $order): void
    {
        $orderId = (string) $order->id;
        DB::update('UPDATE products SET stock = stock + ? WHERE id = ?', [(int) $order->quantity, $order->product_id]);
        if ($order->flash_drop_id !== null) {
            DB::update('UPDATE flash_drops SET stock_remaining = stock_remaining + ? WHERE id = ?', [(int) $order->quantity, $order->flash_drop_id]);
        }
        if ($order->coupon_id !== null) {
            DB::table('coupons')->where('id', $order->coupon_id)->update(['redeemed_at' => null]);
        }
        DB::table('gifts')->where('order_id', $orderId)->whereIn('status', ['pending', 'paid'])->update(['status' => 'cancelled']);
        DB::table('blind_pulls')->where('order_id', $orderId)->where('status', 'pending')->update(['status' => 'failed']);
        DB::table('night_holds')->where('order_id', $orderId)->where('status', 'held')->update(['status' => 'cancelled']);
        if ($order->purpose === 'squad' && $order->squad_id !== null) {
            DB::table('squad_members')->where('squad_id', $order->squad_id)->where('user_id', $order->buyer_id)->delete();
        }
    }

    public function onRefundedFully(object $order, bool $restock): void
    {
        $orderId = (string) $order->id;
        if ($restock && $order->purpose !== 'blind_box') {
            DB::update('UPDATE products SET stock = stock + ? WHERE id = ?', [(int) $order->quantity, $order->product_id]);
        }
        DB::table('gifts')->where('order_id', $orderId)->whereIn('status', ['pending', 'paid', 'claimed'])->update(['status' => 'cancelled']);
        DB::table('night_holds')->where('order_id', $orderId)->where('status', 'held')->update(['status' => 'cancelled']);
        DB::table('price_protections')->where('order_id', $orderId)->where('protected_until', '>', now())->update(['protected_until' => now()]);
    }
}

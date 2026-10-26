<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\User;
use App\Services\Commerce\LedgerService;
use App\Services\Commerce\PriceGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\BuildsCommerce;
use Tests\Support\BuildsSocial;
use Tests\TestCase;

/** Price protection pays real, held price drops once, and cannot be farmed. */
#[Group('purchases')]
final class PriceGuardTest extends TestCase
{
    use BuildsCommerce;
    use BuildsSocial;
    use RefreshDatabase;

    private PriceGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = app(PriceGuard::class);
    }

    public function test_only_captured_orders_are_covered(): void
    {
        $buyer = $this->makeUser();
        $product = $this->catalogProduct(10_000, ['created_at' => now()->subDays(5)]);
        $captured = $this->capturedOrder($buyer, $product, 10_000);
        $pending = $this->capturedOrder($buyer, $product, 10_000, ['status' => 'processing', 'payment_status' => 'authorized']);

        $this->guard->cover($captured);
        $this->guard->cover($pending);
        $this->guard->cover($captured);

        $this->assertSame([$captured], DB::table('price_protections')->pluck('order_id')->all());
        $this->assertTrue($this->guard->status($captured, (string) $buyer->id)['protected']);
        $this->assertFalse($this->guard->status($captured, (string) $this->makeUser()->id)['protected'], 'Only the buyer sees the protection.');
    }

    public function test_a_flash_dip_pays_nothing_and_a_held_drop_pays_once(): void
    {
        [$buyer, $product, $order] = $this->protectedPurchase(10_000, quantity: 2);

        // A seller flashes a 1-cent price for a moment, then restores it.
        DB::table('products')->where('id', $product)->update(['price_minor' => 1, 'squad_price_minor' => 1]);
        DB::table('products')->where('id', $product)->update(['price_minor' => 10_000, 'squad_price_minor' => 7_000]);
        $this->assertSame(0, $this->guard->sweep($product));

        $this->setPrice($product, 8_000, heldHours: 30);
        $this->assertSame(1, $this->guard->sweep($product));
        $this->assertSame(0, $this->guard->sweep($product));
        $this->assertSame(4_000, app(LedgerService::class)->balance(LedgerService::available($buyer->id)));

        // A further drop pays only the difference to what was already credited.
        $this->setPrice($product, 7_000, heldHours: 26);
        $this->assertSame(1, $this->guard->sweep());
        $this->assertSame(6_000, app(LedgerService::class)->balance(LedgerService::available($buyer->id)));
        $this->assertSame(6_000, (int) DB::table('price_protections')->where('order_id', $order)->value('refunded_minor'));
        $this->assertSame(2, DB::table('ledger_journals')->where('order_id', $order)->where('kind', 'price_protection')->count());
    }

    public function test_payouts_are_capped_at_half_the_paid_unit_price(): void
    {
        [$buyer, $product] = $this->protectedPurchase(10_000);
        $this->setPrice($product, 100, heldHours: 48);

        $this->guard->sweep($product);

        $this->assertSame(5_000, app(LedgerService::class)->balance(LedgerService::available($buyer->id)));
    }

    public function test_a_currency_switch_drops_before_purchase_and_expired_windows_pay_nothing(): void
    {
        [$buyer, $product, $order] = $this->protectedPurchase(10_000);
        DB::table('products')->where('id', $product)->update(['currency' => 'JPY', 'price_minor' => 50, 'squad_price_minor' => 35]);
        $this->holdCurrent($product, 72);
        $this->assertSame(0, $this->guard->sweep($product), 'JPY 50 is not a drop from USD 100.');

        [$early, $earlyProduct] = $this->protectedPurchase(10_000, priceBeforePurchase: 6_000);
        $this->assertSame(0, $this->guard->sweep($earlyProduct), 'A lower price before the purchase is not a drop.');

        DB::table('price_protections')->where('order_id', $order)->update(['protected_until' => now()->subMinute()]);
        DB::table('products')->where('id', $product)->update(['currency' => 'USD', 'price_minor' => 5_000, 'squad_price_minor' => 3_500]);
        $this->holdCurrent($product, 30);
        $this->assertSame(0, $this->guard->sweep($product));
        $this->assertSame(0, app(LedgerService::class)->balance(LedgerService::available($buyer->id)));
        $this->assertSame(0, app(LedgerService::class)->balance(LedgerService::available($early->id)));
    }

    public function test_refunded_and_disputed_orders_earn_nothing_and_refunds_take_credit_back(): void
    {
        [$buyer, $product, $order] = $this->protectedPurchase(10_000);
        $this->setPrice($product, 8_000, heldHours: 30);
        $this->guard->sweep($product);
        $this->assertSame(2_000, app(LedgerService::class)->balance(LedgerService::available($buyer->id)));

        $intent = (string) DB::table('orders')->where('id', $order)->value('stripe_payment_intent_id');
        $this->stripe()->refund($intent, 5_000, 'USD', 'half-refund', 'requested_by_customer');
        $this->sendWebhook($this->stripe()->event('charge.refunded', $intent))->assertOk();
        $this->assertSame(1_000, app(LedgerService::class)->balance(LedgerService::available($buyer->id)), 'Half refunded, half the credit kept.');

        $this->setPrice($product, 6_000, heldHours: 25);
        $this->assertSame(0, $this->guard->sweep($product), 'A refunded order is no longer protected.');

        [, $disputedProduct, $disputed] = $this->protectedPurchase(10_000);
        DB::table('orders')->where('id', $disputed)->update(['payment_status' => 'disputed']);
        $this->setPrice($disputedProduct, 5_000, heldHours: 30);
        $this->assertSame(0, $this->guard->sweep($disputedProduct));
    }

    public function test_the_scheduled_sweep_runs_the_same_rules(): void
    {
        [$buyer, $product] = $this->protectedPurchase(10_000);
        $this->setPrice($product, 9_000, heldHours: 25);

        Artisan::call('price-protection:sweep');
        Artisan::call('price-protection:sweep', ['--product' => $product]);

        $this->assertSame(1_000, app(LedgerService::class)->balance(LedgerService::available($buyer->id)));
    }

    /** @return array{0: User, 1: string, 2: string} */
    private function protectedPurchase(int $unit, int $quantity = 1, ?int $priceBeforePurchase = null): array
    {
        $buyer = $this->makeUser();
        $product = $this->catalogProduct($unit);
        if ($priceBeforePurchase !== null) {
            DB::table('products')->where('id', $product)->update(['price_minor' => $priceBeforePurchase, 'squad_price_minor' => intdiv($priceBeforePurchase * 7, 10)]);
            DB::table('products')->where('id', $product)->update(['price_minor' => $unit, 'squad_price_minor' => intdiv($unit * 7, 10)]);
        }
        $this->shiftHistory($product, 24 * 10);
        $order = $this->capturedOrder($buyer, $product, $unit * $quantity, [
            'quantity' => $quantity,
            'unit_price_minor' => $unit,
            'created_at' => now()->subDays(2),
        ]);
        if ($priceBeforePurchase !== null) {
            // Make the lower price a period entirely before the purchase.
            DB::update("UPDATE product_price_history SET valid_from = now() - interval '9 days', valid_to = now() - interval '8 days' WHERE product_id = ? AND price_minor = ?", [$product, $priceBeforePurchase]);
            DB::update("UPDATE product_price_history SET valid_to = now() - interval '9 days' WHERE product_id = ? AND valid_to IS NOT NULL AND valid_to > now() - interval '9 days' AND price_minor <> ?", [$product, $priceBeforePurchase]);
            DB::update("UPDATE product_price_history SET valid_from = now() - interval '8 days' WHERE product_id = ? AND valid_to IS NULL", [$product]);
        }
        $this->guard->cover($order);

        return [$buyer, $product, $order];
    }

    private function setPrice(string $product, int $price, int $heldHours): void
    {
        DB::table('products')->where('id', $product)->update(['price_minor' => $price, 'squad_price_minor' => intdiv($price * 7, 10)]);
        $this->holdCurrent($product, $heldHours);
    }

    /** The current price went live $hours ago (and the previous one ended then). */
    private function holdCurrent(string $product, int $hours): void
    {
        $current = DB::table('product_price_history')->where('product_id', $product)->whereNull('valid_to')->first();
        $at = now()->subHours($hours);
        $previous = DB::table('product_price_history')->where('product_id', $product)->where('valid_to', $current->valid_from)->first();
        if ($previous !== null) {
            DB::table('product_price_history')->where('product_id', $product)->where('valid_from', $previous->valid_from)
                ->update(['valid_from' => min((string) $previous->valid_from, (string) $at->copy()->subSecond()), 'valid_to' => $at]);
        }
        DB::table('product_price_history')->where('product_id', $product)->whereNull('valid_to')->update(['valid_from' => $at]);
    }

    private function shiftHistory(string $product, int $hours): void
    {
        DB::update(
            'UPDATE product_price_history SET valid_from = valid_from - make_interval(hours => ?), valid_to = valid_to - make_interval(hours => ?) WHERE product_id = ?',
            [$hours, $hours, $product],
        );
    }
}

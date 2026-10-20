<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use App\Services\Commerce\CatalogImageIndexer;
use App\Services\Commerce\LedgerService;
use App\Services\Payments\FakePaymentGateway;
use App\Services\Payments\OrderPayments;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\StripeWebhookHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;

/**
 * Commerce fixtures on top of BuildsSocial: catalog products, saved cards known to the fake Stripe,
 * orders that are already captured (with a matching PaymentIntent in the fake), signed webhooks.
 */
trait BuildsCommerce
{
    protected const WEBHOOK_SECRET = 'whsec_test_secret';

    protected function stripe(): FakePaymentGateway
    {
        $gateway = app(PaymentGateway::class);
        assert($gateway instanceof FakePaymentGateway);

        return $gateway;
    }

    /** @param  array<string, mixed>  $overrides */
    protected function catalogProduct(int $price = 1000, array $overrides = []): string
    {
        $id = (string) Str::uuid7();
        DB::table('products')->insert($overrides + [
            'id' => $id,
            'title' => 'Piece '.substr($id, 0, 8),
            'brand' => 'Test',
            'image_url' => 'https://example.com/p.jpg',
            'price_minor' => $price,
            'squad_price_minor' => intdiv($price * 70, 100),
            'slug' => 'p-'.str_replace('-', '', $id),
            'currency' => 'USD',
            'stock' => 5,
            'ships_from' => 'US',
            'shipping_minor' => 0,
            'vat_bps' => 0,
            'duty_bps' => 0,
            'handling_days' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /** A seller whose Stripe account is verified (their listings are searchable). */
    protected function verifiedSeller(): User
    {
        $user = $this->makeUser();
        DB::table('sellers')->insert([
            'user_id' => $user->id,
            'legal_name' => 'Seller '.$user->username,
            'country' => 'US',
            'registration_number' => 'REG-'.substr((string) $user->id, -8),
            'status' => 'verified',
            'stripe_account_id' => 'acct_'.substr(str_replace('-', '', (string) $user->id), -16),
            'charges_enabled' => true,
            'payouts_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    /**
     * A listing from a verified seller whose photo is indexed for visual search.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function indexedProduct(string $imageBytes, int $price = 1000, array $overrides = []): string
    {
        $id = $this->catalogProduct($price, $overrides + ['seller_id' => $this->verifiedSeller()->id]);
        Assert::assertSame('indexed', app(CatalogImageIndexer::class)->indexBytes($id, $imageBytes));

        return $id;
    }

    /** A default card attached to the user's (fake) Stripe customer. */
    protected function savedCard(User $user, ?string $paymentMethodId = null): string
    {
        $customer = 'cus_test_'.substr(hash('sha256', 'customer:'.$user->id), 0, 14);
        DB::table('users')->where('id', $user->id)->update(['stripe_customer_id' => $customer]);
        $paymentMethodId ??= 'pm_test_'.substr(str_replace('-', '', (string) $user->id), -12);
        $this->stripe()->addPaymentMethod($paymentMethodId, $customer);
        DB::table('saved_payment_methods')->where('user_id', $user->id)->update(['is_default' => false]);
        DB::table('saved_payment_methods')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $user->id,
            'stripe_payment_method_id' => $paymentMethodId,
            'brand' => 'visa',
            'last4' => '4242',
            'exp_month' => 12,
            'exp_year' => 2030,
            'is_default' => true,
            'created_at' => now(),
        ]);

        return $paymentMethodId;
    }

    /**
     * An order whose payment was captured, with a succeeded PaymentIntent in the fake Stripe.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function capturedOrder(User $buyer, string $productId, int $total, array $overrides = []): string
    {
        $id = (string) Str::uuid7();
        $intent = 'pi_seed_'.substr(str_replace('-', '', $id), -16);
        $at = $overrides['created_at'] ?? now();
        DB::table('orders')->insert($overrides + [
            'id' => $id,
            'buyer_id' => $buyer->id,
            'product_id' => $productId,
            'quantity' => 1,
            'unit_price_minor' => $total,
            'discount_bps' => 0,
            'total_minor' => $total,
            'currency' => 'USD',
            'status' => 'paid',
            'payment_status' => 'captured',
            'purpose' => 'checkout',
            'stripe_payment_intent_id' => $intent,
            'captured_at' => $at,
            'delivered_at' => $at,
            'idempotency_key' => 'seed-'.$id,
            'created_at' => $at,
        ]);
        $order = DB::table('orders')->where('id', $id)->first();
        DB::table('payment_authorizations')->insert([
            'id' => (string) Str::uuid7(),
            'buyer_id' => $buyer->id,
            'product_id' => $productId,
            'order_id' => $id,
            'purpose' => 'checkout',
            'status' => 'captured',
            'authorized_minor' => (int) $order->total_minor,
            'captured_minor' => (int) $order->total_minor,
            'currency' => $order->currency,
            'idempotency_key' => 'seed-'.$id,
            'stripe_payment_intent_id' => $order->stripe_payment_intent_id,
            'expires_at' => $at,
            'capture_after' => $at,
            'captured_at' => $at,
            'created_at' => $at,
        ]);
        if ($order->stripe_payment_intent_id !== null) {
            $this->stripe()->seedIntent((string) $order->stripe_payment_intent_id, (int) $order->total_minor, (string) $order->currency, 'succeeded', [
                'order_id' => $id,
                'buyer_id' => (string) $buyer->id,
                'purpose' => 'checkout',
            ]);
        }
        // As the capture would have: the card money is on the ledger.
        app(LedgerService::class)->recordSale($id, (int) $order->total_minor, (string) $order->currency);

        return $id;
    }

    /** The undo window (or squad / night-hold deadline) has passed for this order. */
    protected function expireUndoWindow(string $orderId): void
    {
        DB::table('payment_authorizations')->where('order_id', $orderId)->update(['capture_after' => now()->subSecond()]);
    }

    protected function captureDue(): int
    {
        return app(OrderPayments::class)->captureDue();
    }

    /** @param  array<string, mixed>  $event */
    protected function sendWebhook(array $event, ?int $timestamp = null, ?string $secret = null): TestResponse
    {
        config(['services.stripe.webhook_secret' => self::WEBHOOK_SECRET]);
        $payload = json_encode($event, JSON_THROW_ON_ERROR);

        return $this->call('POST', '/api/v1/stripe/webhook', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => StripeWebhookHandler::sign($payload, $secret ?? self::WEBHOOK_SECRET, $timestamp),
            'CONTENT_TYPE' => 'application/json',
        ], $payload);
    }

    /** @return array<string, string> */
    protected function key(string $prefix = 'k'): array
    {
        return ['Idempotency-Key' => $prefix.'-'.Str::lower(Str::random(20))];
    }
}

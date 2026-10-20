<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Models\User;
use App\Services\Commerce\LedgerService;
use App\Services\Commerce\ScanAndTapService;
use App\Services\Payments\OrderPayments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsCommerce;
use Tests\Support\BuildsSocial;
use Tests\TestCase;

/**
 * Every purchase path end to end against the Stripe simulator: authorization, undo window, capture,
 * declines, 3-D Secure, lost responses and timeouts. The invariant checked everywhere: stock, money and
 * order state never disagree, whatever Stripe answers (or fails to answer).
 */
final class CheckoutPaymentFlowTest extends TestCase
{
    use BuildsCommerce;
    use BuildsSocial;
    use RefreshDatabase;

    public function test_checkout_authorizes_then_captures_after_the_undo_window_with_every_effect_once(): void
    {
        [$buyer, $product, $post, $creator] = $this->shopperWithTaggedReel(10_000);
        Sanctum::actingAs($buyer);

        $response = $this->postJson('/api/v1/checkout', [
            'product_id' => $product,
            'post_id' => $post,
            'share_publicly' => true,
            'expected_total_minor' => 10_000,
        ], $this->key('co'))->assertCreated()
            ->assertJsonPath('data.status', 'processing')
            ->assertJsonPath('data.payment_status', 'authorized')
            ->assertJsonPath('data.next_action', null);
        $order = (string) $response->json('data.id');
        $this->assertNotNull($response->json('data.undo_until'));
        $this->assertSame(4, $this->stock($product));
        $this->assertSame(0, DB::table('ledger_journals')->where('order_id', $order)->count(), 'Nothing is posted before capture.');
        $this->assertSame(0, DB::table('activity_events')->where('product_id', $product)->count());

        $this->assertSame(0, $this->captureDue(), 'Inside the undo window nothing is captured.');
        $this->expireUndoWindow($order);
        $this->assertSame(1, $this->captureDue());
        $this->assertSame(0, $this->captureDue());

        $row = DB::table('orders')->where('id', $order)->first();
        $this->assertSame('paid', $row->status);
        $this->assertSame('captured', $row->payment_status);
        $this->assertSame(1, $this->countCalls('capture'));
        $this->assertSame(1, DB::table('ledger_journals')->where('order_id', $order)->where('kind', 'sale')->count());
        $this->assertSame(800, app(LedgerService::class)->balance(LedgerService::pending($creator->id)), 'Creator commission (8%) is pending.');
        $this->assertSame(1, DB::table('activity_events')->where('product_id', $product)->count());
        $this->assertSame(1, DB::table('price_protections')->where('order_id', $order)->count());

        // A late succeeded webhook, after the job captured: nothing moves twice.
        $intent = (string) $row->stripe_payment_intent_id;
        $this->sendWebhook($this->stripe()->event('payment_intent.succeeded', $intent))->assertOk();
        $this->assertSame(1, DB::table('ledger_journals')->where('order_id', $order)->where('kind', 'sale')->count());
        $this->assertSame(800, app(LedgerService::class)->balance(LedgerService::pending($creator->id)));
    }

    public function test_a_declined_card_releases_stock_coupon_and_flash_units_and_posts_nothing(): void
    {
        $buyer = $this->makeUser();
        $this->savedCard($buyer);
        $product = $this->catalogProduct(10_000);
        $drop = $this->flashDrop($product, 2000, 3);
        $coupon = $this->coupon($buyer, 1000);
        $this->stripe()->failNext('authorize', 'decline');
        Sanctum::actingAs($buyer);

        $response = $this->postJson('/api/v1/checkout', [
            'product_id' => $product,
            'coupon_id' => $coupon,
        ], $this->key('decline'))->assertCreated()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.payment_status', 'failed')
            ->assertJsonPath('data.failure_code', 'card_declined');

        $this->assertSame(5, $this->stock($product));
        $this->assertSame(3, (int) DB::table('flash_drops')->where('id', $drop)->value('stock_remaining'));
        $this->assertNull(DB::table('coupons')->where('id', $coupon)->value('redeemed_at'));
        $this->assertSame(0, DB::table('ledger_journals')->where('order_id', $response->json('data.id'))->count());
        $this->assertSame(0, $this->captureDue());
    }

    public function test_three_d_secure_hands_the_secret_to_the_app_once_and_never_stores_it(): void
    {
        $buyer = $this->makeUser();
        $this->savedCard($buyer);
        $product = $this->catalogProduct(5_000);
        $this->stripe()->failNext('authorize', 'requires_action');
        Sanctum::actingAs($buyer);
        $headers = $this->key('sca');

        $first = $this->postJson('/api/v1/checkout/one-tap', ['product_id' => $product], $headers)->assertCreated()
            ->assertJsonPath('data.order.payment_status', 'requires_action')
            ->assertJsonPath('data.next_action.type', 'use_stripe_sdk');
        $secret = (string) $first->json('data.next_action.client_secret');
        $this->assertStringContainsString('_secret_', $secret);
        $stored = (string) DB::table('idempotency_keys')->where('key', $headers['Idempotency-Key'])->value('response_body');
        $this->assertStringNotContainsString($secret, $stored);
        $this->postJson('/api/v1/checkout/one-tap', ['product_id' => $product], $headers)->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('data.next_action', null);

        $order = (string) $first->json('data.order.id');
        $intent = (string) DB::table('orders')->where('id', $order)->value('stripe_payment_intent_id');
        $this->stripe()->completeAction($intent);
        $this->postJson('/api/v1/orders/'.$order.'/sync')->assertOk()->assertJsonPath('data.payment_status', 'authorized');
        $this->assertSame(4, $this->stock($product));
    }

    public function test_an_abandoned_three_d_secure_step_is_cancelled_and_restocked(): void
    {
        $buyer = $this->makeUser();
        $this->savedCard($buyer);
        $product = $this->catalogProduct(5_000);
        $this->stripe()->failNext('authorize', 'requires_action');
        Sanctum::actingAs($buyer);
        $order = (string) $this->postJson('/api/v1/checkout/one-tap', ['product_id' => $product], $this->key('abandon'))->json('data.order.id');
        DB::table('payment_authorizations')->where('order_id', $order)->update(['created_at' => now()->subHour()]);

        app(OrderPayments::class)->reconcile();

        $this->assertSame('voided', DB::table('orders')->where('id', $order)->value('payment_status'));
        $this->assertSame('cancelled', DB::table('orders')->where('id', $order)->value('status'));
        $this->assertSame(5, $this->stock($product));
        $this->assertSame(1, $this->countCalls('cancel'));
    }

    public function test_a_request_lost_before_stripe_stays_pending_and_the_reconciler_authorizes_it_once(): void
    {
        $buyer = $this->makeUser();
        $this->savedCard($buyer);
        $product = $this->catalogProduct(3_000);
        $this->stripe()->failNext('authorize', 'timeout_before');
        Sanctum::actingAs($buyer);

        $order = (string) $this->postJson('/api/v1/checkout/one-tap', ['product_id' => $product], $this->key('lost'))
            ->assertCreated()
            ->assertJsonPath('data.order.payment_status', 'pending')
            ->json('data.order.id');
        $this->assertSame(4, $this->stock($product), 'The unit stays reserved while the outcome is unknown.');
        $this->assertCount(0, $this->stripe()->authorizations);

        $this->makeReconcilable($order);
        app(OrderPayments::class)->reconcile();

        $this->assertSame('authorized', DB::table('orders')->where('id', $order)->value('payment_status'));
        $this->assertCount(1, $this->stripe()->authorizations);
    }

    public function test_a_response_lost_after_stripe_charged_is_replayed_not_charged_twice(): void
    {
        $buyer = $this->makeUser();
        $this->savedCard($buyer);
        $product = $this->catalogProduct(3_000);
        $this->stripe()->failNext('authorize', 'timeout_after');
        Sanctum::actingAs($buyer);

        $order = (string) $this->postJson('/api/v1/checkout/one-tap', ['product_id' => $product], $this->key('replay'))
            ->assertJsonPath('data.order.payment_status', 'pending')
            ->json('data.order.id');
        $this->assertCount(1, $this->stripe()->authorizations, 'Stripe did authorize; only our response was lost.');

        $this->makeReconcilable($order);
        app(OrderPayments::class)->reconcile();
        app(OrderPayments::class)->reconcile();

        $this->assertSame('authorized', DB::table('orders')->where('id', $order)->value('payment_status'));
        $this->assertCount(1, $this->stripe()->intents, 'The retry with the same key replayed the stored result.');
        $keys = array_column(array_filter($this->stripe()->calls, fn (array $call): bool => $call['method'] === 'authorize'), 'key');
        $this->assertSame(['order:'.$order.':authorize'], array_values(array_unique($keys)));
    }

    public function test_payments_that_never_resolve_are_released_after_the_last_attempt(): void
    {
        $buyer = $this->makeUser();
        $this->savedCard($buyer);
        $product = $this->catalogProduct(3_000);
        $this->stripe()->failNext('authorize', 'timeout_before', OrderPayments::MAX_AUTHORIZE_ATTEMPTS + 2);
        Sanctum::actingAs($buyer);
        $order = (string) $this->postJson('/api/v1/checkout/one-tap', ['product_id' => $product], $this->key('never'))->json('data.order.id');

        for ($i = 0; $i <= OrderPayments::MAX_AUTHORIZE_ATTEMPTS; $i++) {
            $this->makeReconcilable($order);
            app(OrderPayments::class)->reconcile();
        }

        $this->assertSame('failed', DB::table('orders')->where('id', $order)->value('payment_status'));
        $this->assertSame('authorization_timeout', DB::table('payment_authorizations')->where('order_id', $order)->value('last_error'));
        $this->assertSame(5, $this->stock($product));
    }

    public function test_stripe_unavailable_releases_the_order_immediately(): void
    {
        $buyer = $this->makeUser();
        $this->savedCard($buyer);
        $product = $this->catalogProduct(3_000);
        $this->stripe()->failNext('authorize', 'unavailable');
        Sanctum::actingAs($buyer);

        $this->postJson('/api/v1/checkout/one-tap', ['product_id' => $product], $this->key('down'))
            ->assertCreated()
            ->assertJsonPath('data.order.payment_status', 'failed')
            ->assertJsonPath('data.order.failure_code', 'payments_unavailable');
        $this->assertSame(5, $this->stock($product));
    }

    public function test_a_stale_price_is_refused_before_anything_is_reserved(): void
    {
        $buyer = $this->makeUser();
        $this->savedCard($buyer);
        $product = $this->catalogProduct(10_000);
        Sanctum::actingAs($buyer);

        $this->postJson('/api/v1/checkout', ['product_id' => $product, 'expected_total_minor' => 9_000], $this->key('stale'))
            ->assertStatus(409)
            ->assertJsonPath('code', 'PRICE_CHANGED')
            ->assertJsonPath('meta.total.amount_minor', 10_000);
        $this->assertSame(5, $this->stock($product));
        $this->assertSame(0, DB::table('orders')->where('buyer_id', $buyer->id)->count());
        $this->assertCount(0, $this->stripe()->authorizations);
    }

    public function test_discounts_never_stack_past_the_cap(): void
    {
        $buyer = $this->makeUser();
        $this->savedCard($buyer);
        $product = $this->catalogProduct(10_000);
        $this->flashDrop($product, 4000, 5);
        $coupon = $this->coupon($buyer, 3000);
        $squad = (string) $this->openSquadWithMembers($buyer, $product, 5);
        Sanctum::actingAs($buyer);

        $this->postJson('/api/v1/checkout', [
            'product_id' => $product,
            'squad_id' => $squad,
            'coupon_id' => $coupon,
        ], $this->key('stack'))->assertCreated()
            ->assertJsonPath('data.discount_bps', 5000)
            ->assertJsonPath('data.total.amount_minor', 5_000);
    }

    public function test_orders_above_the_single_purchase_limit_are_refused(): void
    {
        $buyer = $this->makeUser();
        $this->savedCard($buyer);
        $product = $this->catalogProduct((int) config('commerce.max_order_minor') + 1);
        Sanctum::actingAs($buyer);

        $this->postJson('/api/v1/checkout/one-tap', ['product_id' => $product], $this->key('big'))
            ->assertStatus(422)->assertJsonPath('code', 'ORDER_LIMIT');
        $this->assertSame(5, $this->stock($product));
    }

    public function test_no_saved_card_and_paused_accounts_reserve_nothing(): void
    {
        $buyer = $this->makeUser();
        $product = $this->catalogProduct(1_000);
        Sanctum::actingAs($buyer);
        $this->postJson('/api/v1/checkout/one-tap', ['product_id' => $product], $this->key('nocard'))
            ->assertStatus(422)->assertJsonPath('code', 'PAYMENT_METHOD_REQUIRED');

        $this->savedCard($buyer);
        DB::table('users')->where('id', $buyer->id)->update(['paused_at' => now()]);
        $this->postJson('/api/v1/checkout/one-tap', ['product_id' => $product], $this->key('paused'))
            ->assertStatus(409)->assertJsonPath('code', 'ACCOUNT_PAUSED');
        $this->assertSame(5, $this->stock($product));
        $this->assertSame(0, DB::table('orders')->where('buyer_id', $buyer->id)->count());
    }

    public function test_undo_inside_the_window_voids_and_restocks_and_is_refused_after(): void
    {
        $buyer = $this->makeUser();
        $this->savedCard($buyer);
        $product = $this->catalogProduct(2_000);
        Sanctum::actingAs($buyer);
        $undo = (string) $this->postJson('/api/v1/checkout/one-tap', ['product_id' => $product], $this->key('u1'))->json('data.order.id');
        $late = (string) $this->postJson('/api/v1/checkout/one-tap', ['product_id' => $product], $this->key('u2'))->json('data.order.id');

        $this->postJson('/api/v1/orders/'.$undo.'/cancel', [], $this->key('c1'))->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame('voided', DB::table('orders')->where('id', $undo)->value('payment_status'));

        $this->expireUndoWindow($late);
        $this->postJson('/api/v1/orders/'.$late.'/cancel', [], $this->key('c2'))->assertStatus(409)->assertJsonPath('code', 'NOT_CANCELLABLE');
        $this->assertSame(4, $this->stock($product));

        $stranger = $this->makeUser();
        Sanctum::actingAs($stranger);
        $this->postJson('/api/v1/orders/'.$late.'/cancel', [], $this->key('c3'))->assertStatus(404);
        $this->getJson('/api/v1/orders/'.$late)->assertStatus(404);
        $this->getJson('/api/v1/orders/not-a-uuid')->assertStatus(404);
    }

    public function test_a_capture_that_wins_the_race_against_undo_is_reported_not_cancelled(): void
    {
        $buyer = $this->makeUser();
        $this->savedCard($buyer);
        $product = $this->catalogProduct(2_000);
        Sanctum::actingAs($buyer);
        $order = (string) $this->postJson('/api/v1/checkout/one-tap', ['product_id' => $product], $this->key('race'))->json('data.order.id');
        // Stripe captured (e.g. from another worker) a moment before the buyer's cancel reached it.
        $intent = (string) DB::table('orders')->where('id', $order)->value('stripe_payment_intent_id');
        $this->stripe()->capture($intent, null, 'USD', 'external-capture');

        $this->postJson('/api/v1/orders/'.$order.'/cancel', [], $this->key('cancel'))->assertStatus(409);

        $this->assertSame('paid', DB::table('orders')->where('id', $order)->value('status'));
        $this->assertSame('captured', DB::table('orders')->where('id', $order)->value('payment_status'));
        $this->assertSame(4, $this->stock($product));
    }

    public function test_a_late_night_burst_is_held_until_morning_then_captured(): void
    {
        $buyer = $this->makeUser(['timezone' => 'Pacific/Kiritimati']);
        $this->savedCard($buyer);
        $product = $this->catalogProduct(1_000, ['stock' => 10]);
        $this->travelTo(now()->setTimezone('Pacific/Kiritimati')->setTime(2, 30)->utc());
        try {
            foreach (['n1', 'n2'] as $key) {
                app(ScanAndTapService::class)->oneTap($buyer, $product, 1, $key.'-'.Str::random(10));
            }
            $held = app(ScanAndTapService::class)->oneTap($buyer, $product, 1, 'n3-'.Str::random(10));
            $orderId = (string) $held['order']['id'];
            $this->assertSame('held', DB::table('night_holds')->where('order_id', $orderId)->value('status'));
            $captureAfter = (string) DB::table('payment_authorizations')->where('order_id', $orderId)->value('capture_after');
            $this->assertSame('06:00', now()->parse($captureAfter)->setTimezone('Pacific/Kiritimati')->format('H:i'));
        } finally {
            $this->travelBack();
        }
        $this->expireUndoWindow($orderId);
        $this->captureDue();
        $this->assertSame('released', DB::table('night_holds')->where('order_id', $orderId)->value('status'));
    }

    public function test_the_monthly_spend_limit_counts_what_was_kept_and_cannot_be_raced_by_parallel_taps(): void
    {
        $buyer = $this->makeUser();
        $this->savedCard($buyer);
        DB::table('spend_limits')->insert(['user_id' => $buyer->id, 'monthly_limit_minor' => 5_000, 'night_hold' => false]);
        $product = $this->catalogProduct(3_000, ['stock' => 10]);
        $this->capturedOrder($buyer, $product, 3_000, ['amount_refunded_minor' => 3_000, 'status' => 'cancelled', 'payment_status' => 'refunded']);
        Sanctum::actingAs($buyer);

        $this->postJson('/api/v1/checkout/one-tap', ['product_id' => $product], $this->key('s1'))->assertCreated();
        $this->postJson('/api/v1/checkout/one-tap', ['product_id' => $product], $this->key('s2'))->assertStatus(409)->assertJsonPath('code', 'SPEND_LIMIT');
    }

    /** @return array{0: User, 1: string, 2: string, 3: User} */
    private function shopperWithTaggedReel(int $price): array
    {
        $buyer = $this->makeUser();
        $this->savedCard($buyer);
        $creator = $this->makeUser();
        $product = $this->catalogProduct($price);
        $post = (string) Str::uuid7();
        DB::table('feed_posts')->insert([
            'id' => $post,
            'user_id' => $creator->id,
            'kind' => 'media',
            'audience' => 'public',
            'media_type' => 'image',
            'media_path' => 'posts/'.$post.'.jpg',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('reel_product_tags')->insert(['post_id' => $post, 'product_id' => $product, 'created_at' => now()]);

        return [$buyer, $product, $post, $creator];
    }

    private function flashDrop(string $product, int $bps, int $units): string
    {
        $id = (string) Str::uuid7();
        DB::table('flash_drops')->insert([
            'id' => $id,
            'product_id' => $product,
            'discount_bps' => $bps,
            'stock_remaining' => $units,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addMinutes(10),
            'created_at' => now(),
        ]);

        return $id;
    }

    private function coupon(User $user, int $bps): string
    {
        $id = (string) Str::uuid7();
        DB::table('coupons')->insert(['id' => $id, 'user_id' => $user->id, 'label' => 'Test', 'discount_bps' => $bps, 'expires_at' => now()->addDay(), 'created_at' => now()]);

        return $id;
    }

    private function openSquadWithMembers(User $host, string $product, int $members): string
    {
        $squad = (string) Str::uuid7();
        DB::table('squads')->insert([
            'id' => $squad,
            'product_id' => $product,
            'host_id' => $host->id,
            'discount_bps_per_member' => 500,
            'max_members' => 5,
            'floor_price_minor' => 1_000,
            'expires_at' => now()->addDay(),
            'created_at' => now(),
        ]);
        DB::table('squad_members')->insert(['squad_id' => $squad, 'user_id' => $host->id, 'joined_at' => now()]);
        for ($i = 1; $i < $members; $i++) {
            DB::table('squad_members')->insert(['squad_id' => $squad, 'user_id' => $this->makeUser()->id, 'joined_at' => now()]);
        }

        return $squad;
    }

    private function makeReconcilable(string $order): void
    {
        DB::table('payment_authorizations')->where('order_id', $order)->update([
            'created_at' => now()->subMinute(),
            'next_attempt_at' => null,
        ]);
    }

    private function stock(string $product): int
    {
        return (int) DB::table('products')->where('id', $product)->value('stock');
    }

    private function countCalls(string $method): int
    {
        return count(array_filter($this->stripe()->calls, fn (array $call): bool => $call['method'] === $method));
    }
}

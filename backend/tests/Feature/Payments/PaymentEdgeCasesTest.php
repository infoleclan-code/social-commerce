<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\Commerce\CheckoutService;
use App\Services\Commerce\LedgerService;
use App\Services\Commerce\PriceGuard;
use App\Services\Payments\OrderPayments;
use App\Services\Payments\PaymentIntentResult;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsCommerce;
use Tests\Support\BuildsSocial;
use Tests\TestCase;

/**
 * The branches that only bad luck reaches: Stripe failing mid-capture, mid-cancel and mid-refund,
 * intents that do not match, compensations, referral commission budgets, and invalid identifiers.
 */
final class PaymentEdgeCasesTest extends TestCase
{
    use BuildsCommerce;
    use BuildsSocial;
    use RefreshDatabase;

    public function test_unknown_orders_and_settled_payments_are_handled_without_side_effects(): void
    {
        $payments = app(OrderPayments::class);
        foreach ([fn () => $payments->authorize((string) Str::uuid7()), fn () => $payments->sync((string) Str::uuid7()), fn () => $payments->refund((string) Str::uuid7(), 100, 'requested_by_customer', true)] as $call) {
            try {
                $call();
                $this->fail('An unknown order was processed.');
            } catch (ApiException $missing) {
                $this->assertSame('ORDER_NOT_FOUND', $missing->errorCode);
            }
        }

        $order = $this->capturedOrder($this->makeUser(), $this->catalogProduct(), 2_000);
        $this->assertSame('captured', $payments->authorize($order)->paymentStatus, 'A settled payment is never authorized again.');
        $this->assertSame('unknown', $payments->apply((string) Str::uuid7(), new PaymentIntentResult('pi_x', 'succeeded', 1, 0, 1, 'USD'), 'test')->paymentStatus);
        $this->assertSame(1, DB::table('payment_anomalies')->where('kind', 'unknown_intent')->count());

        $payments->refund($order, 2_000, 'requested_by_customer', true);
        $payments->refund($order, 2_000, 'requested_by_customer', true);
        $this->assertCount(1, $this->stripe()->refunds, 'Nothing left to refund the second time.');
    }

    public function test_a_capture_that_times_out_is_retried_later_and_a_refused_one_is_reported(): void
    {
        [$order, $intent] = $this->authorized(3_000);
        $this->expireUndoWindow($order);
        $this->stripe()->failNext('capture', 'timeout_before');

        $this->captureDue();
        $auth = DB::table('payment_authorizations')->where('order_id', $order)->first();
        $this->assertSame('authorized', $auth->status);
        $this->assertSame('capture_retry', $auth->last_error);
        $this->assertGreaterThan(now(), now()->parse($auth->next_attempt_at));

        DB::table('payment_authorizations')->where('order_id', $order)->update(['next_attempt_at' => now()->subSecond()]);
        $this->captureDue();
        $this->assertSame('captured', DB::table('orders')->where('id', $order)->value('payment_status'));

        [$other] = $this->authorized(3_000);
        $this->expireUndoWindow($other);
        DB::table('payment_authorizations')->where('order_id', $other)->update(['stripe_payment_intent_id' => 'pi_gone']);
        $this->captureDue();
        $this->assertSame('capture_rejected:resource_missing', DB::table('payment_authorizations')->where('order_id', $other)->value('last_error'));
        $this->assertSame(1, DB::table('payment_anomalies')->where('kind', 'capture_failed')->count());
    }

    public function test_cancel_refund_and_sync_survive_stripe_outages(): void
    {
        $buyer = $this->buyer();
        [$order] = $this->authorized(3_000, $buyer);
        $this->stripe()->failNext('cancel', 'timeout_before');
        Sanctum::actingAs($buyer);
        $this->postJson('/api/v1/orders/'.$order.'/cancel', [], $this->key('c'))->assertStatus(503)->assertJsonPath('code', 'PAYMENTS_UNAVAILABLE');
        $this->postJson('/api/v1/orders/'.$order.'/cancel', [], $this->key('c'))->assertOk()->assertJsonPath('data.payment_status', 'voided');

        $kept = $this->capturedOrder($buyer, $this->catalogProduct(), 2_500, ['created_at' => now()->subDay()]);
        $this->stripe()->failNext('refund', 'timeout_after');
        $this->postJson('/api/v1/orders/'.$kept.'/withdraw', [], $this->key('w'))->assertStatus(503);
        $this->postJson('/api/v1/orders/'.$kept.'/withdraw', [], $this->key('w'))->assertOk();
        $this->assertCount(1, $this->stripe()->refunds, 'The retry replayed the refund Stripe already made.');

        [$pending] = $this->authorized(3_000, $buyer);
        $this->stripe()->failNext('retrieve', 'unavailable');
        $this->postJson('/api/v1/orders/'.$pending.'/sync')->assertOk()->assertJsonPath('data.payment_status', 'authorized');
        $this->getJson('/api/v1/orders/'.$pending)->assertOk()->assertJsonPath('data.undo_until', fn (?string $until): bool => $until !== null);
    }

    public function test_cancelling_a_payment_stripe_never_answered_closes_it_and_a_late_hold_is_released(): void
    {
        $buyer = $this->buyer();
        $product = $this->catalogProduct(2_000);
        $this->stripe()->failNext('authorize', 'timeout_after');
        Sanctum::actingAs($buyer);
        $order = (string) $this->postJson('/api/v1/checkout/one-tap', ['product_id' => $product], $this->key('t'))->json('data.order.id');
        $this->postJson('/api/v1/orders/'.$order.'/cancel', [], $this->key('x'))->assertOk()->assertJsonPath('data.payment_status', 'voided');
        $this->assertSame(5, (int) DB::table('products')->where('id', $product)->value('stock'));

        // The hold Stripe did place shows up later: it is cancelled, the order stays closed.
        $intent = (string) array_key_first($this->stripe()->intents);
        $this->sendWebhook($this->stripe()->event('payment_intent.amount_capturable_updated', $intent))->assertOk();
        $this->assertSame('canceled', $this->stripe()->intents[$intent]['status']);
        $this->assertSame('voided', DB::table('orders')->where('id', $order)->value('payment_status'));

        // Syncing a payment Stripe never answered re-sends the authorization.
        $this->stripe()->failNext('authorize', 'timeout_before');
        $next = (string) $this->postJson('/api/v1/checkout/one-tap', ['product_id' => $product], $this->key('t2'))->json('data.order.id');
        $this->postJson('/api/v1/orders/'.$next.'/sync')->assertOk()->assertJsonPath('data.payment_status', 'authorized');
    }

    public function test_an_order_that_is_not_cancellable_says_so(): void
    {
        $buyer = $this->buyer();
        $paid = $this->capturedOrder($buyer, $this->catalogProduct(), 1_000);
        Sanctum::actingAs($buyer);
        $this->postJson('/api/v1/orders/'.$paid.'/cancel', [], $this->key('n'))->assertStatus(409)->assertJsonPath('code', 'NOT_CANCELLABLE');
    }

    public function test_intents_that_do_not_belong_to_the_authorization_are_not_applied(): void
    {
        [$order, $intent] = $this->authorized(3_000);
        $payments = app(OrderPayments::class);

        $payments->apply($order, new PaymentIntentResult('pi_other', 'succeeded', 3_000, 0, 3_000, 'USD'), 'test');
        $payments->apply($order, new PaymentIntentResult($intent, 'succeeded', 3_000, 0, 9_000, 'USD'), 'test');
        $this->assertSame('authorized', DB::table('orders')->where('id', $order)->value('payment_status'));
        $this->assertSame(['order_mismatch', 'amount_mismatch'], DB::table('payment_anomalies')->orderBy('created_at')->pluck('kind')->all());

        // Captured for less than the order (a partial capture made in the dashboard): kept, but flagged.
        $payments->apply($order, new PaymentIntentResult($intent, 'succeeded', 3_000, 0, 2_000, 'USD'), 'test');
        $this->assertSame('captured', DB::table('orders')->where('id', $order)->value('payment_status'));
        $this->assertSame(2, DB::table('payment_anomalies')->where('kind', 'amount_mismatch')->count());
        $this->assertSame(2_000, app(LedgerService::class)->balance(LedgerService::PLATFORM_SALES));
    }

    public function test_a_failing_background_job_is_logged_and_left_for_the_next_run(): void
    {
        [$order] = $this->authorized(3_000);
        $this->expireUndoWindow($order);
        $this->stripe()->failNext('capture', 'explode');
        Log::spy();

        $this->assertSame(1, $this->captureDue());

        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => $message === 'payments.capture.failed' && $context['order_id'] === $order && ! str_contains(json_encode($context), 'secret'));
        $this->assertSame('authorized', DB::table('orders')->where('id', $order)->value('payment_status'));
        DB::table('payment_authorizations')->where('order_id', $order)->update(['next_attempt_at' => now()->subSecond()]);
        $this->captureDue();
        $this->assertSame('captured', DB::table('orders')->where('id', $order)->value('payment_status'), 'The next run captures it.');
    }

    public function test_referral_commission_respects_the_budget_and_never_pays_the_buyer_or_creator(): void
    {
        $creator = $this->makeUser();
        $sharer = $this->makeUser();
        $buyer = $this->buyer();
        $product = $this->catalogProduct(10_000);
        $post = $this->reelWithTag($creator, $product, 1_500);
        $token = $this->referral($sharer, $product, $post, 5_000);
        $selfToken = $this->referral($buyer, $product, $post, 5_000);
        $creatorToken = $this->referral($creator, $product, $post, 5_000);
        Sanctum::actingAs($buyer);

        $order = (string) $this->postJson('/api/v1/checkout', ['product_id' => $product, 'post_id' => $post, 'referral_token' => $token], $this->key('r1'))->json('data.id');
        $self = (string) $this->postJson('/api/v1/checkout', ['product_id' => $product, 'post_id' => $post, 'referral_token' => $selfToken], $this->key('r2'))->json('data.id');
        $creatorRef = (string) $this->postJson('/api/v1/checkout', ['product_id' => $product, 'referral_token' => $creatorToken], $this->key('r3'))->json('data.id');
        foreach ([$order, $self, $creatorRef] as $id) {
            $this->expireUndoWindow($id);
        }
        $this->captureDue();

        $ledger = app(LedgerService::class);
        // Budget 20% of 10 000 = 2 000. Through the reel: creator 15% = 1 500 and the sharer gets only the
        // remaining 500 (not the link's 50%). Without the reel, the creator's own link earns as a sharer,
        // capped at the whole budget (2 000).
        $this->assertSame(1_500 + 1_500 + 2_000, $ledger->balance(LedgerService::pending($creator->id)));
        $this->assertSame(500, $ledger->balance(LedgerService::pending($sharer->id)));
        $this->assertSame(0, $ledger->balance(LedgerService::pending($buyer->id)), 'No commission on your own purchase.');
        $this->assertNull(DB::table('orders')->where('id', $self)->value('referral_link_id'));
        $this->assertNotNull(DB::table('orders')->where('id', $creatorRef)->value('referral_link_id'));
        $this->assertSame(1, DB::table('commission_ledger')->where('order_id', $order)->count());
        Sanctum::actingAs($sharer);
        $this->getJson('/api/v1/me/ledger')->assertOk()->assertJsonPath('data.pending.amount_minor', 500);
    }

    public function test_checkout_refuses_inconsistent_requests_without_reserving_anything(): void
    {
        $buyer = $this->buyer();
        $product = $this->catalogProduct(1_000);
        $other = $this->catalogProduct(1_000);
        $blocked = $this->catalogProduct(1_000, ['listing_status' => 'review']);
        Sanctum::actingAs($buyer);
        $checkout = app(CheckoutService::class);

        $checkout->checkout($buyer, $product, null, null, null, 1, 'conflict-key-1');
        $this->assertApiError('IDEMPOTENCY_KEY_REUSED', fn () => $checkout->checkout($buyer, $other, null, null, null, 1, 'conflict-key-1'));
        $this->assertApiError('OUT_OF_STOCK', fn () => $checkout->checkout($buyer, $blocked, null, null, null, 1, 'blocked-key-1'));
        $this->assertApiError('OUT_OF_STOCK', fn () => $checkout->checkout($buyer, $product, null, null, null, 9, 'too-many-01'));
        $this->assertApiError('COUPON_INVALID', fn () => $checkout->checkout($buyer, $product, null, null, (string) Str::uuid7(), 1, 'coupon-key-1'));
        $squad = $checkout->openSquad($this->makeUser(), $product, null)['id'];
        $this->assertApiError('SQUAD_CLOSED', fn () => $checkout->checkout($buyer, $product, null, $squad, null, 1, 'squad-key-01'));
        $this->assertApiError('OUT_OF_STOCK', fn () => $checkout->openSquad($buyer, $blocked, null));
        $this->assertApiError('SQUAD_CLOSED', fn () => $checkout->joinSquad($buyer, (string) Str::uuid7()));

        $replay = $checkout->checkout($buyer, $product, null, null, null, 1, 'conflict-key-1');
        $this->assertTrue($replay['replayed']);
        $this->assertSame(4, (int) DB::table('products')->where('id', $product)->value('stock'));

        $joined = $checkout->joinSquad($buyer, $squad);
        $this->assertSame(2, $joined['members']);
        $withSquad = $checkout->checkout($buyer, $product, null, $squad, null, 1, 'squad-key-02');
        $this->assertSame(1_000, $withSquad['discount_bps'], 'Two members x 5%.');
    }

    public function test_price_guard_settle_ignores_unknown_and_refunded_orders_and_ledger_noops(): void
    {
        $buyer = $this->makeUser();
        $order = $this->capturedOrder($buyer, $this->catalogProduct(), 4_000);
        $guard = app(PriceGuard::class);
        $guard->cover($order);
        $this->assertSame(0, $guard->settle((string) Str::uuid7()));
        DB::table('orders')->where('id', $order)->update(['payment_status' => 'partially_refunded', 'amount_refunded_minor' => 100]);
        $this->assertSame(0, $guard->settle($order));

        $ledger = app(LedgerService::class);
        $this->assertNull($ledger->pendCommission($buyer->id, $order, 0, 'USD'));
        $ledger->settlePriceProtection((string) Str::uuid7(), 100, 100);
        $ledger->settlePriceProtection($order, 0, 4_000);
        $ledger->creditPriceProtection($order, $buyer->id, 400, 'USD');
        $ledger->settlePriceProtection($order, 0, 4_000);
        $this->assertSame(400, $ledger->balance(LedgerService::available($buyer->id)));
        $this->assertSame(0, DB::table('ledger_journals')->where('kind', 'price_protection_reverse')->count());
    }

    public function test_multipart_uploads_are_part_of_the_idempotency_fingerprint(): void
    {
        $author = $this->makeUser();
        $post = $this->reelWithTag($author, $this->catalogProduct(), 800);
        $route = '/api/v1/feed/'.$post.'/auto-tag';
        Route::post('/api/v1/test-idempotent-upload', fn () => response()->json(['ok' => true], 201))
            ->middleware(['api', 'auth:sanctum', 'idempotent']);
        Sanctum::actingAs($author);
        $headers = ['Idempotency-Key' => 'upload-key-001', 'Accept' => 'application/json'];

        $this->post('/api/v1/test-idempotent-upload', ['frame' => UploadedFile::fake()->createWithContent('a.jpg', 'first')], $headers)->assertCreated();
        $this->post('/api/v1/test-idempotent-upload', ['frame' => UploadedFile::fake()->createWithContent('a.jpg', 'first')], $headers)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
        $this->post('/api/v1/test-idempotent-upload', ['frame' => UploadedFile::fake()->createWithContent('a.jpg', 'other')], $headers)->assertStatus(422)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
        $this->assertNotSame('', $route);
    }

    public function test_payment_operations_on_orders_in_the_wrong_state_do_nothing(): void
    {
        $payments = app(OrderPayments::class);
        [$order, $intent] = $this->authorized(2_000);

        $payments->capture((string) Str::uuid7());
        $payments->applyRefund($order, 2_000, true);
        $payments->applyDispute((string) Str::uuid7(), 'dp_test_nothing', 'lost', 100, 'USD', null);
        $this->assertSame('authorized', DB::table('orders')->where('id', $order)->value('payment_status'), 'Nothing refunds an uncaptured hold.');
        $this->assertSame(0, DB::table('payment_disputes')->count());

        $this->assertApiError('ORDER_NOT_FOUND', fn () => $payments->cancelByBuyer($this->makeUser(), $order));
        $this->assertApiError('REFUND_REJECTED', fn () => $payments->refund($order, 2_000, 'requested_by_customer', true));
        $this->assertSame([], array_filter($this->stripe()->calls, fn (array $call): bool => $call['method'] === 'refund'), 'Refused before Stripe is asked.');

        $captured = $this->capturedOrder($this->makeUser(), $this->catalogProduct(), 1_500);
        $this->stripe()->intents[(string) DB::table('orders')->where('id', $captured)->value('stripe_payment_intent_id')]['status'] = 'canceled';
        $this->assertApiError('REFUND_REJECTED', fn () => $payments->refund($captured, 1_500, 'requested_by_customer', true));
        $this->assertSame('requires_capture', $this->stripe()->intents[$intent]['status']);
    }

    /** @return array{0: string, 1: string} */
    private function authorized(int $price, ?User $buyer = null): array
    {
        $buyer ??= $this->buyer();
        Sanctum::actingAs($buyer);
        $order = (string) $this->postJson('/api/v1/checkout/one-tap', ['product_id' => $this->catalogProduct($price)], $this->key('a'))->json('data.order.id');

        return [$order, (string) DB::table('orders')->where('id', $order)->value('stripe_payment_intent_id')];
    }

    private function buyer(): User
    {
        $buyer = $this->makeUser();
        $this->savedCard($buyer);

        return $buyer;
    }

    private function reelWithTag(User $creator, string $product, int $commissionBps): string
    {
        $post = (string) Str::uuid7();
        DB::table('feed_posts')->insert(['id' => $post, 'user_id' => $creator->id, 'kind' => 'media', 'audience' => 'public', 'media_type' => 'image', 'media_path' => 'posts/'.$post.'.jpg', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('reel_product_tags')->insert(['post_id' => $post, 'product_id' => $product, 'commission_bps' => $commissionBps, 'created_at' => now()]);

        return $post;
    }

    private function referral(User $sharer, string $product, string $post, int $bps): string
    {
        $token = Str::random(16);
        DB::table('referral_links')->insert(['id' => (string) Str::uuid7(), 'token' => $token, 'user_id' => $sharer->id, 'post_id' => $post, 'product_id' => $product, 'commission_bps' => $bps, 'created_at' => now()]);

        return $token;
    }

    private function assertApiError(string $code, Closure $call): void
    {
        try {
            $call();
            $this->fail('Expected '.$code);
        } catch (ApiException $refused) {
            $this->assertSame($code, $refused->errorCode);
        }
    }
}

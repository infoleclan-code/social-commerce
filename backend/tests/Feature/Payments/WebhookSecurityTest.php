<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Services\Commerce\LedgerService;
use App\Services\Payments\StripeWebhookHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\BuildsCommerce;
use Tests\Support\BuildsSocial;
use Tests\TestCase;

/**
 * Attacks on and accidents around the Stripe webhook: forgery, replay, tampering, oversized bodies,
 * events from the wrong mode, events that do not match our records, duplicates and reordering.
 */
#[Group('purchases')]
final class WebhookSecurityTest extends TestCase
{
    use BuildsCommerce;
    use BuildsSocial;
    use RefreshDatabase;

    public function test_forged_tampered_replayed_and_oversized_requests_are_refused(): void
    {
        [$order, $intent] = $this->authorizedOrder(2_000);
        $event = $this->stripe()->event('payment_intent.canceled', $intent, ['status' => 'canceled']);
        $payload = json_encode($event, JSON_THROW_ON_ERROR);
        config(['services.stripe.webhook_secret' => self::WEBHOOK_SECRET]);
        $post = fn (string $body, ?string $signature, array $server = []) => $this->call('POST', '/api/v1/stripe/webhook', [], [], [], $server + array_filter([
            'HTTP_STRIPE_SIGNATURE' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ]), $body);

        $post($payload, null)->assertStatus(400)->assertJsonPath('code', 'INVALID_SIGNATURE');
        $post($payload, StripeWebhookHandler::sign($payload, 'whsec_attacker'))->assertStatus(400);
        $post(str_replace('"canceled"', '"succeeded"', $payload), StripeWebhookHandler::sign($payload, self::WEBHOOK_SECRET))->assertStatus(400);
        $post($payload, StripeWebhookHandler::sign($payload, self::WEBHOOK_SECRET, time() - 3600))->assertStatus(400);
        $post($payload, StripeWebhookHandler::sign($payload, self::WEBHOOK_SECRET, time() + 3600))->assertStatus(400);
        $post($payload, 't='.time().',v1='.str_repeat('a', 5000))->assertStatus(400);
        $post('not json', StripeWebhookHandler::sign('not json', self::WEBHOOK_SECRET))->assertStatus(400);
        $big = str_repeat(' ', StripeWebhookHandler::MAX_PAYLOAD_BYTES + 1);
        $post($big, StripeWebhookHandler::sign($big, self::WEBHOOK_SECRET))->assertStatus(413)->assertJsonPath('code', 'PAYLOAD_TOO_LARGE');
        config(['services.stripe.webhook_secret' => '']);
        $post($payload, StripeWebhookHandler::sign($payload, self::WEBHOOK_SECRET))->assertStatus(400);

        $this->assertSame('authorized', DB::table('orders')->where('id', $order)->value('payment_status'));
        $this->assertSame(0, DB::table('stripe_events')->count());
    }

    public function test_a_valid_event_in_the_wrong_mode_or_with_wrong_amounts_changes_nothing(): void
    {
        [$order, $intent] = $this->authorizedOrder(2_000);

        $this->sendWebhook($this->stripe()->event('payment_intent.canceled', $intent, ['status' => 'canceled'], livemode: true))
            ->assertOk()->assertJsonPath('outcome', 'anomaly');
        $this->sendWebhook($this->stripe()->event('payment_intent.succeeded', $intent, ['status' => 'succeeded', 'amount' => 1, 'amount_received' => 1]))
            ->assertOk()->assertJsonPath('outcome', 'anomaly');
        $this->sendWebhook($this->stripe()->event('payment_intent.succeeded', $intent, ['status' => 'succeeded', 'currency' => 'eur', 'amount_received' => 200000]))
            ->assertOk()->assertJsonPath('outcome', 'anomaly');
        $this->sendWebhook($this->stripe()->event('payment_intent.succeeded', $intent, ['status' => 'succeeded', 'metadata' => ['order_id' => (string) Str::uuid7()], 'amount_received' => 200000]))
            ->assertOk()->assertJsonPath('outcome', 'anomaly');

        $this->assertSame('authorized', DB::table('orders')->where('id', $order)->value('payment_status'));
        $this->assertEqualsCanonicalizing(
            ['livemode_mismatch', 'amount_mismatch', 'currency_mismatch', 'order_mismatch'],
            DB::table('payment_anomalies')->pluck('kind')->all(),
        );
        $this->assertSame(4, DB::table('stripe_events')->where('outcome', 'anomaly')->count());
    }

    public function test_events_for_intents_we_do_not_know_or_other_peoples_orders_are_recorded_not_applied(): void
    {
        [$order, $intent] = $this->authorizedOrder(2_000);
        $this->stripe()->seedIntent('pi_foreign_1', 2_000, 'USD', 'succeeded', ['order_id' => $order, 'buyer_id' => (string) Str::uuid7()]);

        $this->sendWebhook($this->stripe()->event('payment_intent.succeeded', 'pi_foreign_1'))->assertOk()->assertJsonPath('outcome', 'anomaly');
        $this->stripe()->seedIntent('pi_unknown_2', 2_000, 'USD', 'succeeded');
        $this->sendWebhook($this->stripe()->event('payment_intent.succeeded', 'pi_unknown_2'))->assertOk()->assertJsonPath('outcome', 'anomaly');
        $this->sendWebhook(['id' => 'evt_test_misc', 'object' => 'event', 'type' => 'customer.created', 'livemode' => false, 'data' => ['object' => ['object' => 'customer']]])
            ->assertOk()->assertJsonPath('outcome', 'ignored');

        $this->assertSame('authorized', DB::table('orders')->where('id', $order)->value('payment_status'));
        $this->assertSame($intent, DB::table('orders')->where('id', $order)->value('stripe_payment_intent_id'));
    }

    public function test_duplicates_and_out_of_order_events_converge_on_the_right_state(): void
    {
        [$order, $intent] = $this->authorizedOrder(2_000);
        $this->stripe()->capture($intent, null, 'USD', 'external-capture');
        $succeeded = $this->stripe()->event('payment_intent.succeeded', $intent);
        $staleAuthorized = $this->stripe()->event('payment_intent.amount_capturable_updated', $intent, [
            'status' => 'requires_capture', 'amount_capturable' => 2000, 'amount_received' => 0,
        ]);

        $this->sendWebhook($succeeded)->assertJsonPath('outcome', 'applied');
        $this->sendWebhook($succeeded)->assertJsonPath('outcome', 'duplicate');
        $this->sendWebhook($staleAuthorized)->assertJsonPath('outcome', 'applied');

        $this->assertSame('captured', DB::table('orders')->where('id', $order)->value('payment_status'));
        $this->assertSame('paid', DB::table('orders')->where('id', $order)->value('status'));
        $this->assertSame(1, DB::table('ledger_journals')->where('order_id', $order)->where('kind', 'sale')->count());
        $this->assertSame(0, (int) DB::table('payment_authorizations')->where('order_id', $order)->where('status', '<>', 'captured')->count());
    }

    public function test_a_failed_three_d_secure_and_a_canceled_intent_release_the_order(): void
    {
        $buyer = $this->makeUser();
        $this->savedCard($buyer);
        $product = $this->catalogProduct(2_000);
        $this->stripe()->failNext('authorize', 'requires_action');
        Sanctum::actingAs($buyer);
        $failed = (string) $this->postJson('/api/v1/checkout/one-tap', ['product_id' => $product], $this->key('3ds'))->json('data.order.id');
        $failedIntent = (string) DB::table('orders')->where('id', $failed)->value('stripe_payment_intent_id');
        $this->stripe()->completeAction($failedIntent, false);
        $this->sendWebhook($this->stripe()->event('payment_intent.payment_failed', $failedIntent))->assertJsonPath('outcome', 'applied');

        [$canceled, $canceledIntent] = $this->authorizedOrder(2_000, $product);
        $this->stripe()->cancel($canceledIntent, 'dashboard-cancel');
        $this->sendWebhook($this->stripe()->event('payment_intent.canceled', $canceledIntent))->assertJsonPath('outcome', 'applied');

        $this->assertSame(['failed', 'cancelled'], [DB::table('orders')->where('id', $failed)->value('payment_status'), DB::table('orders')->where('id', $failed)->value('status')]);
        $this->assertSame(['voided', 'cancelled'], [DB::table('orders')->where('id', $canceled)->value('payment_status'), DB::table('orders')->where('id', $canceled)->value('status')]);
        $this->assertSame(5, (int) DB::table('products')->where('id', $product)->value('stock'));
    }

    public function test_partial_then_full_refunds_reverse_commission_in_proportion_and_restock_once(): void
    {
        $creator = $this->makeUser();
        $product = $this->catalogProduct(10_000);
        $order = $this->capturedOrder($this->makeUser(), $product, 10_000, ['creator_id' => $creator->id]);
        $ledger = app(LedgerService::class);
        $ledger->pendCommission($creator->id, $order, 800, 'USD');
        $intent = (string) DB::table('orders')->where('id', $order)->value('stripe_payment_intent_id');

        $this->stripe()->refund($intent, 2_500, 'USD', 'dash-partial', 'requested_by_customer');
        $partial = $this->stripe()->event('charge.refunded', $intent);
        $this->sendWebhook($partial)->assertJsonPath('outcome', 'applied');
        $this->assertSame(['paid', 'partially_refunded', 2_500], $this->orderMoney($order));
        $this->assertSame(600, $ledger->balance(LedgerService::pending($creator->id)));

        $this->stripe()->refund($intent, 7_500, 'USD', 'dash-rest', 'requested_by_customer');
        $this->sendWebhook($this->stripe()->event('charge.refunded', $intent))->assertJsonPath('outcome', 'applied');
        $this->sendWebhook($partial)->assertJsonPath('outcome', 'duplicate');
        $replayedPartial = $partial;
        $replayedPartial['id'] = 'evt_test_partial_again';
        $this->sendWebhook($replayedPartial)->assertJsonPath('outcome', 'applied');

        $this->assertSame(['cancelled', 'refunded', 10_000], $this->orderMoney($order));
        $this->assertSame(0, $ledger->balance(LedgerService::pending($creator->id)));
        $this->assertSame(6, (int) DB::table('products')->where('id', $product)->value('stock'), 'Restocked exactly once.');
        $this->assertSame(0, $ledger->balance(LedgerService::PLATFORM_CARD_CLEARING));
    }

    public function test_disputes_hold_commission_and_a_lost_dispute_reverses_without_restocking(): void
    {
        $creator = $this->makeUser();
        $product = $this->catalogProduct(10_000);
        $order = $this->capturedOrder($this->makeUser(), $product, 10_000, ['creator_id' => $creator->id, 'created_at' => now()->subDays(20)]);
        $ledger = app(LedgerService::class);
        $ledger->pendCommission($creator->id, $order, 800, 'USD');
        $intent = (string) DB::table('orders')->where('id', $order)->value('stripe_payment_intent_id');

        $this->sendWebhook($this->stripe()->event('charge.dispute.created', $intent))->assertJsonPath('outcome', 'applied');
        $this->assertSame('disputed', DB::table('orders')->where('id', $order)->value('payment_status'));
        $this->assertSame(0, $ledger->releaseKept($order), 'No payout while the money is disputed.');

        $this->sendWebhook($this->stripe()->event('charge.dispute.closed', $intent))->assertJsonPath('outcome', 'applied');
        $this->assertSame(['cancelled', 'refunded', 10_000], $this->orderMoney($order));
        $this->assertSame(0, $ledger->balance(LedgerService::pending($creator->id)));
        $this->assertSame(5, (int) DB::table('products')->where('id', $product)->value('stock'), 'A chargeback does not put the item back on the shelf.');

        // The "created" event arriving after "closed" cannot reopen it.
        $this->sendWebhook($this->stripe()->event('charge.dispute.created', $intent));
        $this->assertSame('lost', DB::table('payment_disputes')->value('status'));
        $this->assertSame('refunded', DB::table('orders')->where('id', $order)->value('payment_status'));
    }

    public function test_a_won_dispute_restores_the_payment(): void
    {
        $order = $this->capturedOrder($this->makeUser(), $this->catalogProduct(), 3_000);
        $intent = (string) DB::table('orders')->where('id', $order)->value('stripe_payment_intent_id');
        $this->sendWebhook($this->stripe()->event('charge.dispute.created', $intent));
        $this->sendWebhook($this->stripe()->event('charge.dispute.closed', $intent, ['status' => 'won']));

        $this->assertSame(['paid', 'captured', 0], $this->orderMoney($order));
        $this->assertSame('won', DB::table('payment_disputes')->value('status'));
    }

    public function test_money_taken_for_a_cancelled_order_is_refunded_automatically(): void
    {
        [$order, $intent] = $this->authorizedOrder(2_000);
        // Our side voided locally (e.g. the buyer cancelled while Stripe was unreachable) ...
        DB::table('payment_authorizations')->where('order_id', $order)->update(['status' => 'voided']);
        DB::table('orders')->where('id', $order)->update(['status' => 'cancelled', 'payment_status' => 'voided']);
        // ... but the authorization was captured anyway.
        $this->stripe()->capture($intent, null, 'USD', 'rogue-capture');

        $this->sendWebhook($this->stripe()->event('payment_intent.succeeded', $intent))->assertJsonPath('outcome', 'anomaly');

        $this->assertSame(1, DB::table('payment_anomalies')->where('kind', 'late_capture')->count());
        $this->assertSame([['id' => $this->stripe()->refunds[0]['id'], 'payment_intent' => $intent, 'amount_minor' => 2_000]], $this->stripe()->refunds);
    }

    public function test_when_our_authorize_response_was_lost_the_webhook_links_the_intent(): void
    {
        $buyer = $this->makeUser();
        $this->savedCard($buyer);
        $product = $this->catalogProduct(2_000);
        $this->stripe()->failNext('authorize', 'timeout_after');
        Sanctum::actingAs($buyer);
        $order = (string) $this->postJson('/api/v1/checkout/one-tap', ['product_id' => $product], $this->key('lost'))->json('data.order.id');
        $this->assertNull(DB::table('orders')->where('id', $order)->value('stripe_payment_intent_id'));

        $intent = array_key_first($this->stripe()->intents);
        $this->sendWebhook($this->stripe()->event('payment_intent.amount_capturable_updated', $intent))->assertJsonPath('outcome', 'applied');

        $this->assertSame($intent, DB::table('orders')->where('id', $order)->value('stripe_payment_intent_id'));
        $this->assertSame('authorized', DB::table('orders')->where('id', $order)->value('payment_status'));
    }

    public function test_malformed_but_signed_events_are_refused_or_recorded(): void
    {
        $this->sendWebhook(['id' => 'not-an-event-id', 'object' => 'event', 'type' => 'payment_intent.succeeded', 'livemode' => false, 'data' => []])
            ->assertStatus(400);
        $this->sendWebhook(['id' => 'evt_test_noobj', 'object' => 'event', 'type' => 'payment_intent.succeeded', 'livemode' => false, 'data' => ['object' => 'x']])
            ->assertOk()->assertJsonPath('outcome', 'ignored');
        // ISK amounts must be whole krónur x100 at Stripe: 12345 cannot be converted, so nothing is applied.
        $this->sendWebhook(['id' => 'evt_test_isk', 'object' => 'event', 'type' => 'payment_intent.succeeded', 'livemode' => false,
            'data' => ['object' => ['id' => 'pi_isk', 'object' => 'payment_intent', 'status' => 'succeeded', 'amount' => 12345, 'currency' => 'isk']]])
            ->assertOk()->assertJsonPath('outcome', 'anomaly');
        $this->sendWebhook(['id' => 'evt_test_ch', 'object' => 'event', 'type' => 'charge.refunded', 'livemode' => false,
            'data' => ['object' => ['id' => 'ch_x', 'object' => 'charge', 'payment_intent' => 'pi_nobody', 'amount_refunded' => 100, 'currency' => 'usd']]])
            ->assertOk()->assertJsonPath('outcome', 'anomaly');

        $order = $this->capturedOrder($this->makeUser(), $this->catalogProduct(), 2_000);
        $intent = (string) DB::table('orders')->where('id', $order)->value('stripe_payment_intent_id');
        $this->sendWebhook($this->stripe()->event('charge.refunded', $intent, ['currency' => 'eur', 'amount_refunded' => 2_000]))
            ->assertOk()->assertJsonPath('outcome', 'anomaly');
        $this->sendWebhook($this->stripe()->event('charge.dispute.created', $intent, ['id' => 'bad id']))
            ->assertOk()->assertJsonPath('outcome', 'anomaly');
        $this->assertSame(['paid', 'captured', 0], $this->orderMoney($order));
    }

    /** @return array{0: string, 1: string} order id, PaymentIntent id */
    private function authorizedOrder(int $price, ?string $product = null): array
    {
        $buyer = $this->makeUser();
        $this->savedCard($buyer);
        $product ??= $this->catalogProduct($price);
        Sanctum::actingAs($buyer);
        $order = (string) $this->postJson('/api/v1/checkout/one-tap', ['product_id' => $product], $this->key('auth'))
            ->assertJsonPath('data.order.payment_status', 'authorized')
            ->json('data.order.id');

        return [$order, (string) DB::table('orders')->where('id', $order)->value('stripe_payment_intent_id')];
    }

    /** @return array{0: string, 1: string, 2: int} */
    private function orderMoney(string $order): array
    {
        $row = DB::table('orders')->where('id', $order)->first(['status', 'payment_status', 'amount_refunded_minor']);

        return [(string) $row->status, (string) $row->payment_status, (int) $row->amount_refunded_minor];
    }
}

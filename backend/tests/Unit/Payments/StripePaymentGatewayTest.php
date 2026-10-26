<?php

declare(strict_types=1);

namespace Tests\Unit\Payments;

use App\Services\Payments\AuthorizeRequest;
use App\Services\Payments\PaymentGatewayUnavailable;
use App\Services\Payments\PaymentOutcomeUnknown;
use App\Services\Payments\PaymentRejected;
use App\Services\Payments\StripePaymentGateway;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use RuntimeException;
use Stripe\ApiRequestor;
use Stripe\HttpClient\CurlClient;
use Tests\Support\FakeStripeHttpClient;
use Tests\TestCase;

/**
 * The Stripe gateway through the real stripe-php SDK, with only the HTTP transport faked: what we send
 * (amounts, manual capture, idempotency keys, metadata) and how every class of failure is mapped.
 */
#[Group('purchases')]
final class StripePaymentGatewayTest extends TestCase
{
    private FakeStripeHttpClient $http;

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = new FakeStripeHttpClient;
        ApiRequestor::setHttpClient($this->http);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        parent::tearDown();
    }

    public function test_authorize_sends_a_card_only_manual_capture_intent_with_our_idempotency_key(): void
    {
        $this->http->reply(200, FakeStripeHttpClient::intent('requires_capture', 2500));

        $result = $this->gateway()->authorize($this->request(2500, 'USD'));

        $this->assertTrue($result->isAuthorized());
        $this->assertSame('pi_test_123', $result->id);
        $this->assertSame(2500, $result->amount);
        $this->assertSame(2500, $result->amountCapturable);
        $this->assertSame('USD', $result->currency);
        $this->assertNull($result->clientSecret, 'A client secret is only passed on when the buyer must act.');
        $request = $this->http->requests[0];
        $this->assertSame('post', strtolower($request['method']));
        $this->assertStringEndsWith('/v1/payment_intents', $request['url']);
        $this->assertSame('order:o-1:authorize', $this->http->header(0, 'Idempotency-Key'));
        $this->assertSame(2500, $request['params']['amount']);
        $this->assertSame('usd', $request['params']['currency']);
        $this->assertSame('manual', $request['params']['capture_method']);
        $this->assertSame(['card'], $request['params']['payment_method_types']);
        $this->assertContains($request['params']['confirm'], [true, 'true']);
        $this->assertContains($request['params']['use_stripe_sdk'], [true, 'true']);
        $this->assertSame('cus_1', $request['params']['customer']);
        $this->assertSame('pm_1', $request['params']['payment_method']);
        $this->assertSame(['order_id' => 'o-1', 'buyer_id' => 'b-1', 'purpose' => 'one_tap'], $request['params']['metadata']);
        $this->assertArrayNotHasKey('setup_future_usage', $request['params']);
    }

    public function test_zero_decimal_and_stripe_special_currencies_are_converted_once(): void
    {
        $this->http->reply(200, FakeStripeHttpClient::intent('requires_capture', 500, 'jpy'));
        $this->gateway()->authorize($this->request(500, 'JPY'));
        $this->assertSame(500, $this->http->requests[0]['params']['amount']);

        // ISK is zero-decimal in ISO but Stripe wants it x100.
        $this->http->reply(200, FakeStripeHttpClient::intent('requires_capture', 70000, 'isk'));
        $result = $this->gateway()->authorize($this->request(700, 'ISK'));
        $this->assertSame(70000, $this->http->requests[1]['params']['amount']);
        $this->assertSame(700, $result->amount);
    }

    public function test_three_d_secure_returns_the_client_secret_for_the_app(): void
    {
        $this->http->reply(200, FakeStripeHttpClient::intent('requires_action'));

        $result = $this->gateway()->authorize($this->request(2500, 'USD'));

        $this->assertTrue($result->needsAction());
        $this->assertSame('pi_test_123_secret_abc', $result->clientSecret);
    }

    public function test_a_declined_card_is_a_result_not_an_exception(): void
    {
        $this->http->reply(402, ['error' => [
            'type' => 'card_error',
            'code' => 'card_declined',
            'decline_code' => 'insufficient_funds',
            'message' => 'Your card has insufficient funds.',
            'payment_intent' => FakeStripeHttpClient::intent('requires_payment_method', 2500, 'usd', [
                'last_payment_error' => ['code' => 'card_declined', 'decline_code' => 'insufficient_funds'],
            ]),
        ]]);

        $result = $this->gateway()->authorize($this->request(2500, 'USD'));

        $this->assertTrue($result->isFailed());
        $this->assertSame('pi_test_123', $result->id);
        $this->assertSame('insufficient_funds', $result->failureCode);
    }

    public function test_an_unusable_saved_card_fails_the_payment(): void
    {
        $this->http->reply(400, ['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => 'No such PaymentMethod']]);

        $result = $this->gateway()->authorize($this->request(2500, 'USD'));

        $this->assertTrue($result->isFailed());
        $this->assertNull($result->id);
        $this->assertSame('payment_method_unusable', $result->failureCode);
    }

    public function test_a_network_timeout_is_an_unknown_outcome(): void
    {
        $this->http->timeout();
        $this->expectException(PaymentOutcomeUnknown::class);
        $this->gateway()->authorize($this->request(2500, 'USD'));
    }

    public function test_a_stripe_server_error_is_an_unknown_outcome(): void
    {
        $this->http->reply(500, ['error' => ['type' => 'api_error', 'message' => 'Something went wrong']]);
        $this->expectException(PaymentOutcomeUnknown::class);
        $this->gateway()->authorize($this->request(2500, 'USD'));
    }

    public function test_rate_limits_bad_credentials_and_idempotency_misuse_are_not_applied(): void
    {
        foreach ([
            [429, ['error' => ['type' => 'invalid_request_error', 'code' => 'rate_limit', 'message' => 'Too many requests']]],
            [401, ['error' => ['type' => 'invalid_request_error', 'message' => 'Invalid API Key']]],
            [403, ['error' => ['type' => 'invalid_request_error', 'message' => 'Forbidden']]],
            [400, ['error' => ['type' => 'idempotency_error', 'message' => 'Keys for idempotent requests can only be used with the same parameters']]],
        ] as [$status, $body]) {
            $this->http->reply($status, $body);
            try {
                $this->gateway()->authorize($this->request(2500, 'USD'));
                $this->fail("HTTP {$status} was not mapped.");
            } catch (PaymentGatewayUnavailable) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_capture_sends_the_amount_and_key_and_reports_the_real_state_when_refused(): void
    {
        $this->http->reply(200, FakeStripeHttpClient::intent('succeeded', 1800));
        $captured = $this->gateway()->capture('pi_test_123', 1800, 'USD', 'order:o-1:capture:1800');
        $this->assertTrue($captured->isCaptured());
        $this->assertSame(1800, $this->http->requests[0]['params']['amount_to_capture']);
        $this->assertSame('order:o-1:capture:1800', $this->http->header(0, 'Idempotency-Key'));
        $this->assertStringEndsWith('/v1/payment_intents/pi_test_123/capture', $this->http->requests[0]['url']);

        // Already canceled: Stripe refuses the capture, the gateway reports what really happened.
        $this->http->reply(400, ['error' => ['type' => 'invalid_request_error', 'code' => 'payment_intent_unexpected_state', 'message' => 'canceled']])
            ->reply(200, FakeStripeHttpClient::intent('canceled'));
        $this->assertTrue($this->gateway()->capture('pi_test_123', null, 'USD', 'k-2-capture')->isCanceled());
        $this->assertSame([], $this->http->requests[2]['params'] ?? []);
    }

    public function test_cancel_reports_a_capture_that_won_the_race(): void
    {
        $this->http->reply(400, ['error' => ['type' => 'invalid_request_error', 'code' => 'payment_intent_unexpected_state', 'message' => 'succeeded']])
            ->reply(200, FakeStripeHttpClient::intent('succeeded'));

        $result = $this->gateway()->cancel('pi_test_123', 'order:o-1:cancel');

        $this->assertTrue($result->isCaptured());
        $this->assertSame('requested_by_customer', $this->http->requests[0]['params']['cancellation_reason']);
    }

    public function test_refunds_are_keyed_and_a_refused_refund_is_definite(): void
    {
        $this->http->reply(200, ['id' => 're_1', 'object' => 'refund', 'status' => 'succeeded', 'amount' => 2500, 'currency' => 'usd']);
        $refund = $this->gateway()->refund('pi_test_123', 2500, 'USD', 'order:o-1:refund:2500', 'whatever');
        $this->assertSame(['id' => 're_1', 'status' => 'succeeded', 'amount_minor' => 2500], $refund);
        $this->assertSame('pi_test_123', $this->http->requests[0]['params']['payment_intent']);
        $this->assertSame('requested_by_customer', $this->http->requests[0]['params']['reason'], 'Unknown reasons fall back to a valid one.');
        $this->assertSame('order:o-1:refund:2500', $this->http->header(0, 'Idempotency-Key'));

        $this->http->reply(400, ['error' => ['type' => 'invalid_request_error', 'code' => 'charge_already_refunded', 'message' => 'refunded']]);
        $this->expectException(PaymentRejected::class);
        $this->gateway()->refund('pi_test_123', 2500, 'USD', 'order:o-1:refund:5000', 'requested_by_customer');
    }

    public function test_retrieving_a_missing_intent_is_rejected(): void
    {
        $this->http->reply(404, ['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => 'No such payment_intent']]);
        $this->expectException(PaymentRejected::class);
        $this->gateway()->retrieve('pi_missing');
    }

    public function test_setup_intents_and_payment_methods_are_mapped_without_card_numbers(): void
    {
        $this->http->reply(200, ['id' => 'seti_1', 'object' => 'setup_intent', 'status' => 'succeeded', 'customer' => 'cus_1', 'payment_method' => 'pm_1'])
            ->reply(200, ['id' => 'pm_1', 'object' => 'payment_method', 'type' => 'card', 'customer' => 'cus_1', 'card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2030]])
            ->reply(200, ['id' => 'pm_2', 'object' => 'payment_method', 'type' => 'sepa_debit', 'customer' => 'cus_1', 'card' => null])
            ->reply(404, ['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => 'missing']]);

        $this->assertSame(['id' => 'seti_1', 'status' => 'succeeded', 'customer' => 'cus_1', 'payment_method' => 'pm_1'], $this->gateway()->retrieveSetupIntent('seti_1'));
        $card = $this->gateway()->paymentMethod('pm_1');
        $this->assertSame(['id' => 'pm_1', 'customer' => 'cus_1', 'brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2030], $card);
        $this->assertNull($this->gateway()->paymentMethod('pm_2'), 'Only cards can be saved for one-tap.');
        $this->assertNull($this->gateway()->retrieveSetupIntent('seti_missing'));
    }

    public function test_customers_and_setup_intents_carry_idempotency_keys(): void
    {
        $this->http->reply(200, ['id' => 'cus_1', 'object' => 'customer'])
            ->reply(200, ['id' => 'seti_1', 'object' => 'setup_intent', 'client_secret' => 'seti_1_secret']);

        $this->assertSame('cus_1', $this->gateway()->createCustomer('user-1', 'a@example.com', 'customer:user-1'));
        $this->assertSame(['id' => 'seti_1', 'client_secret' => 'seti_1_secret'], $this->gateway()->createSetupIntent('cus_1', 'setup:user-1:k'));
        $this->assertSame('customer:user-1', $this->http->header(0, 'Idempotency-Key'));
        $this->assertSame(['user_id' => 'user-1'], $this->http->requests[0]['params']['metadata']);
        $this->assertSame('setup:user-1:k', $this->http->header(1, 'Idempotency-Key'));
        $this->assertSame('off_session', $this->http->requests[1]['params']['usage']);
    }

    public function test_without_a_secret_nothing_is_sent(): void
    {
        $this->expectException(PaymentGatewayUnavailable::class);
        try {
            (new StripePaymentGateway(''))->authorize($this->request(100, 'USD'));
        } finally {
            $this->assertSame([], $this->http->requests);
        }
    }

    public function test_the_real_transport_gets_bounded_timeouts(): void
    {
        ApiRequestor::setHttpClient(null);
        $gateway = new StripePaymentGateway('sk_test_x', timeoutSeconds: 17, connectTimeoutSeconds: 4);
        (new ReflectionMethod($gateway, 'client'))->invoke($gateway);

        $this->assertSame(17, CurlClient::instance()->getTimeout());
        $this->assertSame(4, CurlClient::instance()->getConnectTimeout());
        $this->assertFalse($gateway->livemode());
        $this->assertTrue((new StripePaymentGateway('sk_live_x'))->livemode());
    }

    public function test_odd_failures_are_classified_conservatively(): void
    {
        // A decline without an intent in the error body still fails cleanly, keeping the decline code.
        $this->http->reply(402, ['error' => ['type' => 'card_error', 'code' => 'card_declined', 'decline_code' => 'do_not_honor', 'message' => 'Declined']]);
        $result = $this->gateway()->authorize($this->request(2500, 'USD'));
        $this->assertTrue($result->isFailed());
        $this->assertNull($result->id);
        $this->assertSame('do_not_honor', $result->failureCode);

        // An unexpected 4xx is "not applied"; a transport bug is "unknown".
        $this->http->reply(418, ['error' => ['type' => 'api_error', 'message' => 'teapot']]);
        try {
            $this->gateway()->authorize($this->request(2500, 'USD'));
            $this->fail('418 was not mapped.');
        } catch (PaymentGatewayUnavailable) {
            $this->addToAssertionCount(1);
        }
        $this->http->fail(new RuntimeException('socket exploded'));
        $this->expectException(PaymentOutcomeUnknown::class);
        $this->gateway()->authorize($this->request(2500, 'USD'));
    }

    public function test_a_missing_payment_method_is_null_and_the_api_version_is_pinned_when_configured(): void
    {
        $this->http->reply(404, ['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => 'No such PaymentMethod']]);
        $this->assertNull($this->gateway()->paymentMethod('pm_missing'));

        $this->http->reply(200, FakeStripeHttpClient::intent('succeeded'));
        (new StripePaymentGateway('sk_test_123', maxNetworkRetries: 0, apiVersion: '2024-06-20'))->retrieve('pi_test_123');
        $this->assertSame('2024-06-20', $this->http->header(1, 'Stripe-Version'));
    }

    private function gateway(): StripePaymentGateway
    {
        return new StripePaymentGateway('sk_test_123', maxNetworkRetries: 0);
    }

    private function request(int $amount, string $currency): AuthorizeRequest
    {
        return new AuthorizeRequest('cus_1', 'pm_1', $amount, $currency, 'order:o-1:authorize', ['order_id' => 'o-1', 'buyer_id' => 'b-1', 'purpose' => 'one_tap']);
    }
}

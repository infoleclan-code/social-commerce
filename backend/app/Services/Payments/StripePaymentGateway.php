<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Support\Currency;
use Stripe\ApiRequestor;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\AuthenticationException;
use Stripe\Exception\CardException;
use Stripe\Exception\IdempotencyException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\PermissionException;
use Stripe\Exception\RateLimitException;
use Stripe\HttpClient\CurlClient;
use Stripe\PaymentIntent;
use Stripe\StripeClient;
use Throwable;

/**
 * Stripe PaymentIntents with manual capture. Card numbers never pass through this app.
 *
 * Transport: bounded timeouts (connect 5 s, total 20 s by default) and the SDK's network retries
 * (exponential backoff on connection errors, 409, 429 and 5xx). Every mutating call carries our
 * idempotency key, so those retries, and ours after a timeout, are replays.
 *
 * Intents are card-only (no redirect methods, no return_url), confirmed at creation, and created with
 * use_stripe_sdk so a 3-D Secure step is handled natively by the app from the client secret.
 */
final class StripePaymentGateway implements PaymentGateway
{
    private ?StripeClient $stripe = null;

    public function __construct(
        private readonly string $secret,
        private readonly int $timeoutSeconds = 20,
        private readonly int $connectTimeoutSeconds = 5,
        private readonly int $maxNetworkRetries = 2,
        private readonly ?string $apiVersion = null,
    ) {}

    public function createCustomer(string $userId, string $email, string $idempotencyKey): string
    {
        $customer = $this->call(fn () => $this->client()->customers->create([
            'email' => $email,
            'metadata' => ['user_id' => $userId],
        ], ['idempotency_key' => $idempotencyKey]));

        return (string) $customer->id;
    }

    public function createSetupIntent(string $customerId, string $idempotencyKey): array
    {
        $intent = $this->call(fn () => $this->client()->setupIntents->create([
            'customer' => $customerId,
            'usage' => 'off_session',
            'payment_method_types' => ['card'],
        ], ['idempotency_key' => $idempotencyKey]));

        return ['id' => (string) $intent->id, 'client_secret' => (string) $intent->client_secret];
    }

    public function retrieveSetupIntent(string $setupIntentId): ?array
    {
        try {
            $intent = $this->call(fn () => $this->client()->setupIntents->retrieve($setupIntentId));
        } catch (InvalidRequestException) {
            return null;
        }
        $customer = $intent->customer;
        $method = $intent->payment_method;

        return [
            'id' => (string) $intent->id,
            'status' => (string) $intent->status,
            'customer' => is_string($customer) ? $customer : ($customer->id ?? null),
            'payment_method' => is_string($method) ? $method : ($method->id ?? null),
        ];
    }

    public function paymentMethod(string $paymentMethodId): ?array
    {
        try {
            $method = $this->call(fn () => $this->client()->paymentMethods->retrieve($paymentMethodId));
        } catch (InvalidRequestException) {
            return null;
        }
        if ($method->type !== 'card' || $method->card === null) {
            return null;
        }
        $customer = $method->customer;

        return [
            'id' => (string) $method->id,
            'customer' => is_string($customer) ? $customer : ($customer->id ?? null),
            'brand' => (string) ($method->card->brand ?? 'card'),
            'last4' => (string) $method->card->last4,
            'exp_month' => $method->card->exp_month !== null ? (int) $method->card->exp_month : null,
            'exp_year' => $method->card->exp_year !== null ? (int) $method->card->exp_year : null,
        ];
    }

    public function supportsTestCards(): bool
    {
        return false;
    }

    public function detachPaymentMethod(string $paymentMethodId): void
    {
        try {
            $this->call(fn () => $this->client()->paymentMethods->detach($paymentMethodId));
        } catch (InvalidRequestException) {
            // Already detached, or the id was never a card.
        }
    }

    public function authorize(AuthorizeRequest $request): PaymentIntentResult
    {
        try {
            $intent = $this->call(fn () => $this->client()->paymentIntents->create(
                $this->paymentIntentParams($request),
                ['idempotency_key' => $request->idempotencyKey],
            ));
        } catch (CardException $declined) {
            return $this->declined($declined, $request);
        } catch (InvalidRequestException $invalid) {
            // A detached, expired, or foreign payment method: nothing was charged and retrying will not help.
            return $this->unusable($invalid, $request);
        }

        return $this->result($intent);
    }

    public function capture(string $paymentIntentId, ?int $amountMinor, string $currency, string $idempotencyKey): PaymentIntentResult
    {
        $params = $amountMinor === null ? [] : ['amount_to_capture' => Currency::toStripe($amountMinor, $currency)];
        try {
            return $this->result($this->call(fn () => $this->client()->paymentIntents->capture($paymentIntentId, $params, ['idempotency_key' => $idempotencyKey])));
        } catch (InvalidRequestException|CardException $refused) {
            // Already captured, canceled, or the authorization expired: report the real state.
            return $this->retrieveAfter($paymentIntentId, $refused);
        }
    }

    public function cancel(string $paymentIntentId, string $idempotencyKey): PaymentIntentResult
    {
        try {
            return $this->result($this->call(fn () => $this->client()->paymentIntents->cancel(
                $paymentIntentId,
                ['cancellation_reason' => 'requested_by_customer'],
                ['idempotency_key' => $idempotencyKey],
            )));
        } catch (InvalidRequestException $refused) {
            return $this->retrieveAfter($paymentIntentId, $refused);
        }
    }

    public function retrieve(string $paymentIntentId): PaymentIntentResult
    {
        try {
            return $this->result($this->call(fn () => $this->client()->paymentIntents->retrieve($paymentIntentId)));
        } catch (InvalidRequestException $missing) {
            throw new PaymentRejected((string) ($missing->getStripeCode() ?? 'resource_missing'));
        }
    }

    public function refund(string $paymentIntentId, int $amountMinor, string $currency, string $idempotencyKey, string $reason): array
    {
        try {
            $refund = $this->call(fn () => $this->client()->refunds->create([
                'payment_intent' => $paymentIntentId,
                'amount' => Currency::toStripe($amountMinor, $currency),
                'reason' => in_array($reason, ['duplicate', 'fraudulent', 'requested_by_customer'], true) ? $reason : 'requested_by_customer',
            ], ['idempotency_key' => $idempotencyKey]));
        } catch (InvalidRequestException $refused) {
            throw new PaymentRejected((string) ($refused->getStripeCode() ?? 'invalid_request'));
        }

        return [
            'id' => (string) $refund->id,
            'status' => (string) $refund->status,
            'amount_minor' => Currency::fromStripe((int) $refund->amount, $currency),
        ];
    }

    /** @return array<string, mixed> */
    public function paymentIntentParams(AuthorizeRequest $request): array
    {
        $params = [
            'amount' => Currency::toStripe($request->amountMinor, $request->currency),
            'currency' => strtolower($request->currency),
            'customer' => $request->customerId,
            'payment_method' => $request->paymentMethodId,
            'payment_method_types' => ['card'],
            'capture_method' => 'manual',
            'confirm' => true,
            'use_stripe_sdk' => true,
            'metadata' => $request->metadata,
        ];
        if (isset($request->metadata['order_id'])) {
            $params['description'] = 'Order '.$request->metadata['order_id'];
        }

        return $params;
    }

    public function livemode(): bool
    {
        return str_starts_with($this->secret, 'sk_live_') || str_starts_with($this->secret, 'rk_live_');
    }

    private function result(PaymentIntent $intent): PaymentIntentResult
    {
        return PaymentIntentResult::fromArray($intent->toArray());
    }

    private function declined(CardException $declined, AuthorizeRequest $request): PaymentIntentResult
    {
        $intent = $declined->getError()?->payment_intent;
        if ($intent !== null) {
            $result = PaymentIntentResult::fromArray($intent->toArray());
            if ($result->failureCode !== null) {
                return $result;
            }
        }

        return new PaymentIntentResult(
            id: $intent?->id !== null ? (string) $intent->id : null,
            status: 'requires_payment_method',
            amount: $request->amountMinor,
            amountCapturable: 0,
            amountReceived: 0,
            currency: strtoupper($request->currency),
            failureCode: (string) ($declined->getDeclineCode() ?? $declined->getStripeCode() ?? 'card_declined'),
            metadata: $request->metadata,
        );
    }

    private function unusable(InvalidRequestException $invalid, AuthorizeRequest $request): PaymentIntentResult
    {
        return new PaymentIntentResult(
            id: null,
            status: 'requires_payment_method',
            amount: $request->amountMinor,
            amountCapturable: 0,
            amountReceived: 0,
            currency: strtoupper($request->currency),
            failureCode: 'payment_method_unusable',
            metadata: $request->metadata,
        );
    }

    /** A capture or cancel Stripe refused (already captured, canceled, expired): report the real state. */
    private function retrieveAfter(string $paymentIntentId, ApiErrorException $cause): PaymentIntentResult
    {
        return $this->retrieve($paymentIntentId);
    }

    /**
     * Unknown outcomes become PaymentOutcomeUnknown, definite non-actions PaymentGatewayUnavailable;
     * card and request errors propagate so the caller maps them to a result.
     *
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    private function call(callable $call): mixed
    {
        try {
            return $call();
        } catch (RateLimitException|AuthenticationException|PermissionException $refused) {
            // RateLimitException extends InvalidRequestException in stripe-php: it must be caught first,
            // or a rate limit would be mistaken for a definite decline.
            throw new PaymentGatewayUnavailable('Stripe refused the request ('.$refused::class.').', previous: $refused);
        } catch (CardException|InvalidRequestException $definite) {
            throw $definite;
        } catch (ApiConnectionException $network) {
            throw new PaymentOutcomeUnknown('Stripe did not answer in time.', previous: $network);
        } catch (IdempotencyException $reused) {
            throw new PaymentGatewayUnavailable('Idempotency key reused with different parameters.', previous: $reused);
        } catch (ApiErrorException $error) {
            if (($error->getHttpStatus() ?? 500) >= 500) {
                throw new PaymentOutcomeUnknown('Stripe returned a server error.', previous: $error);
            }
            throw new PaymentGatewayUnavailable('Stripe rejected the request.', previous: $error);
        } catch (PaymentGatewayUnavailable $unavailable) {
            throw $unavailable;
        } catch (Throwable $unexpected) {
            throw new PaymentOutcomeUnknown('Unexpected error calling Stripe.', previous: $unexpected);
        }
    }

    private function client(): StripeClient
    {
        if ($this->secret === '') {
            throw new PaymentGatewayUnavailable('Card payments are not configured.');
        }
        if ($this->stripe === null) {
            $http = ApiRequestor::httpClient();
            if ($http instanceof CurlClient) {
                $http->setTimeout($this->timeoutSeconds);
                $http->setConnectTimeout($this->connectTimeoutSeconds);
            }
            $config = ['api_key' => $this->secret, 'max_network_retries' => $this->maxNetworkRetries];
            if ($this->apiVersion !== null && $this->apiVersion !== '') {
                $config['stripe_version'] = $this->apiVersion;
            }
            $this->stripe = new StripeClient($config);
        }

        return $this->stripe;
    }
}

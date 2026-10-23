<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Support\Currency;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * In-memory Stripe stand-in for tests and local development, faithful where it matters:
 *  - manual capture, partial capture, cancel, refunds, 3-D Secure (requires_action);
 *  - idempotency: a repeated key replays the stored result, a reused key with different parameters is
 *    refused, a request that failed before reaching "Stripe" stores nothing;
 *  - scripted failures: failNext('authorize', 'timeout_after') applies the request and loses the
 *    response, 'timeout_before' loses the request, 'unavailable' refuses it, 'explode' throws an
 *    unexpected error, 'decline' and 'requires_action' shape the authorization.
 * It never sees card numbers either: payment methods are ids attached to customers.
 *
 * PHPUnit keeps one gateway for the process. A local server and the scheduler are separate
 * processes, so outside tests each call reloads these intents from the cache. That is what lets
 * payments:capture-due and a later refund see an authorization made over HTTP.
 */
final class FakePaymentGateway implements PaymentGateway
{
    private const CACHE_KEY = 'fake-payment-gateway';

    /** True only for the local fake. Tests stay in memory so scripted failures cannot leak. */
    private bool $shared = false;

    public function __construct()
    {
        $this->shared = ! app()->runningUnitTests() && ! app()->environment('testing');
    }

    /** @var array<string, array<string, mixed>> PaymentIntents by id; amounts in minor units */
    public array $intents = [];

    /** @var list<array<string, mixed>> one entry per authorization actually created */
    public array $authorizations = [];

    /** @var list<array{method: string, key: string|null, target: string|null}> every call that reached the fake */
    public array $calls = [];

    /** @var list<array{id: string, payment_intent: string, amount_minor: int}> */
    public array $refunds = [];

    /** @var array<string, array{id: string, customer: string|null, brand: string, last4: string, exp_month: int|null, exp_year: int|null}> */
    public array $paymentMethods = [];

    /** @var array<string, array{id: string, status: string, customer: string|null, payment_method: string|null}> */
    public array $setupIntents = [];

    /** @var array<string, array{fingerprint: string, result: mixed}> */
    private array $replays = [];

    /** @var array<string, list<string>> */
    private array $script = [];

    private int $sequence = 0;

    private int $events = 0;

    public function failNext(string $method, string $behaviour, int $times = 1): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->script[$method][] = $behaviour;
        }
    }

    public function createCustomer(string $userId, string $email, string $idempotencyKey): string
    {
        $this->calls[] = ['method' => 'createCustomer', 'key' => $idempotencyKey, 'target' => $userId];
        $this->interrupt('createCustomer', before: true);

        return $this->remember($idempotencyKey, 'customer:'.$userId, fn (): string => 'cus_test_'.substr(hash('sha256', $idempotencyKey), 0, 14));
    }

    public function createSetupIntent(string $customerId, string $idempotencyKey): array
    {
        $this->begin();
        $this->calls[] = ['method' => 'createSetupIntent', 'key' => $idempotencyKey, 'target' => $customerId];
        $this->interrupt('createSetupIntent', before: true);

        $created = $this->remember($idempotencyKey, 'seti:'.$customerId, function () use ($customerId): array {
            $id = 'seti_test_'.(++$this->sequence).'_'.bin2hex(random_bytes(4));
            $this->setupIntents[$id] = ['id' => $id, 'status' => 'requires_payment_method', 'customer' => $customerId, 'payment_method' => null];

            return ['id' => $id, 'client_secret' => $id.'_secret_test'];
        });
        $this->commit();

        return $created;
    }

    public function retrieveSetupIntent(string $setupIntentId): ?array
    {
        $this->begin();
        $this->calls[] = ['method' => 'retrieveSetupIntent', 'key' => null, 'target' => $setupIntentId];
        $this->interrupt('retrieveSetupIntent', before: true);

        return $this->setupIntents[$setupIntentId] ?? null;
    }

    public function paymentMethod(string $paymentMethodId): ?array
    {
        $this->begin();
        $this->calls[] = ['method' => 'paymentMethod', 'key' => null, 'target' => $paymentMethodId];
        $this->interrupt('paymentMethod', before: true);

        return $this->paymentMethods[$paymentMethodId] ?? null;
    }

    /** Test helper: the app finished the SetupIntent with a new card attached to its customer. */
    public function completeSetup(string $setupIntentId, string $paymentMethodId, string $brand = 'visa', string $last4 = '4242'): void
    {
        $this->begin();
        $intent = $this->setupIntents[$setupIntentId];
        $this->setupIntents[$setupIntentId] = ['status' => 'succeeded', 'payment_method' => $paymentMethodId] + $intent;
        $this->paymentMethods[$paymentMethodId] = [
            'id' => $paymentMethodId,
            'customer' => $intent['customer'],
            'brand' => $brand,
            'last4' => $last4,
            'exp_month' => 12,
            'exp_year' => 2030,
        ];
        $this->commit();
    }

    public function addPaymentMethod(string $paymentMethodId, ?string $customerId, string $brand = 'visa', string $last4 = '4242'): void
    {
        $this->begin();
        $this->paymentMethods[$paymentMethodId] = [
            'id' => $paymentMethodId,
            'customer' => $customerId,
            'brand' => $brand,
            'last4' => $last4,
            'exp_month' => 12,
            'exp_year' => 2030,
        ];
        $this->commit();
    }

    public function authorize(AuthorizeRequest $request): PaymentIntentResult
    {
        $this->begin();
        $this->calls[] = ['method' => 'authorize', 'key' => $request->idempotencyKey, 'target' => $request->metadata['order_id'] ?? null];
        $behaviour = $this->interrupt('authorize', before: true);
        $fingerprint = hash('sha256', json_encode([$request->customerId, $request->paymentMethodId, $request->amountMinor, strtoupper($request->currency), $request->metadata], JSON_THROW_ON_ERROR));

        $result = $this->remember($request->idempotencyKey, $fingerprint, function () use ($request, $behaviour): PaymentIntentResult {
            // Unique across processes too (concurrency tests run one fake per worker).
            $id = 'pi_test_'.str_pad((string) (++$this->sequence), 6, '0', STR_PAD_LEFT).'_'.bin2hex(random_bytes(4));
            $owned = isset($this->paymentMethods[$request->paymentMethodId])
                && $this->paymentMethods[$request->paymentMethodId]['customer'] === $request->customerId;
            $status = match (true) {
                $behaviour === 'decline' => 'requires_payment_method',
                isset($this->paymentMethods[$request->paymentMethodId]) && ! $owned => 'requires_payment_method',
                $behaviour === 'requires_action' => 'requires_action',
                default => 'requires_capture',
            };
            $this->intents[$id] = [
                'id' => $id,
                'status' => $status,
                'amount' => $request->amountMinor,
                'amount_capturable' => $status === 'requires_capture' ? $request->amountMinor : 0,
                'amount_received' => 0,
                'amount_refunded' => 0,
                'currency' => strtoupper($request->currency),
                'customer' => $request->customerId,
                'payment_method' => $request->paymentMethodId,
                'capture_method' => 'manual',
                'metadata' => $request->metadata,
                'failure_code' => $status === 'requires_payment_method' ? ($behaviour === 'decline' ? 'card_declined' : 'payment_method_unusable') : null,
                'latest_charge' => null,
            ];
            $this->authorizations[] = [
                'id' => $id,
                'customer' => $request->customerId,
                'payment_method' => $request->paymentMethodId,
                'amount' => $request->amountMinor,
                'currency' => strtoupper($request->currency),
                'capture_method' => 'manual',
                'key' => $request->idempotencyKey,
                'metadata' => $request->metadata,
            ];

            return $this->result($id);
        });
        $this->interrupt('authorize', before: false, behaviour: $behaviour);

        $this->commit();

        return $result;
    }

    /** Test helper: the buyer finished (or failed) the 3-D Secure step in the app. */
    public function completeAction(string $paymentIntentId, bool $succeed = true): void
    {
        $intent = $this->intents[$paymentIntentId];
        $this->intents[$paymentIntentId] = $succeed
            ? ['status' => 'requires_capture', 'amount_capturable' => $intent['amount']] + $intent
            : ['status' => 'requires_payment_method', 'failure_code' => 'authentication_required'] + $intent;
    }

    /** Test helper: an intent created outside the app (orders inserted directly by a test). */
    public function seedIntent(string $id, int $amountMinor, string $currency, string $status = 'succeeded', array $metadata = []): void
    {
        $this->intents[$id] = [
            'id' => $id,
            'status' => $status,
            'amount' => $amountMinor,
            'amount_capturable' => $status === 'requires_capture' ? $amountMinor : 0,
            'amount_received' => $status === 'succeeded' ? $amountMinor : 0,
            'amount_refunded' => 0,
            'currency' => strtoupper($currency),
            'customer' => 'cus_seeded',
            'payment_method' => 'pm_seeded',
            'capture_method' => 'manual',
            'metadata' => $metadata,
            'failure_code' => null,
            'latest_charge' => $status === 'succeeded' ? 'ch_'.substr($id, 3) : null,
        ];
    }

    public function capture(string $paymentIntentId, ?int $amountMinor, string $currency, string $idempotencyKey): PaymentIntentResult
    {
        $this->begin();
        $this->calls[] = ['method' => 'capture', 'key' => $idempotencyKey, 'target' => $paymentIntentId];
        $behaviour = $this->interrupt('capture', before: true);
        $result = $this->remember($idempotencyKey, 'capture:'.$paymentIntentId.':'.($amountMinor ?? 'all'), function () use ($paymentIntentId, $amountMinor): PaymentIntentResult {
            $intent = $this->existing($paymentIntentId);
            if ($intent['status'] === 'requires_capture') {
                $amount = $amountMinor ?? $intent['amount_capturable'];
                if ($amount >= 1 && $amount <= $intent['amount_capturable']) {
                    $this->intents[$paymentIntentId] = [
                        'status' => 'succeeded',
                        'amount_received' => $amount,
                        'amount_capturable' => 0,
                        'latest_charge' => 'ch_'.substr($paymentIntentId, 3),
                    ] + $intent;
                }
            }

            return $this->result($paymentIntentId);
        });
        $this->interrupt('capture', before: false, behaviour: $behaviour);
        $this->commit();

        return $result;
    }

    public function cancel(string $paymentIntentId, string $idempotencyKey): PaymentIntentResult
    {
        $this->begin();
        $this->calls[] = ['method' => 'cancel', 'key' => $idempotencyKey, 'target' => $paymentIntentId];
        $behaviour = $this->interrupt('cancel', before: true);
        $result = $this->remember($idempotencyKey, 'cancel:'.$paymentIntentId, function () use ($paymentIntentId): PaymentIntentResult {
            $intent = $this->existing($paymentIntentId);
            if (in_array($intent['status'], ['requires_capture', 'requires_action', 'requires_payment_method', 'requires_confirmation'], true)) {
                $this->intents[$paymentIntentId] = ['status' => 'canceled', 'amount_capturable' => 0] + $intent;
            }

            return $this->result($paymentIntentId);
        });
        $this->interrupt('cancel', before: false, behaviour: $behaviour);
        $this->commit();

        return $result;
    }

    public function retrieve(string $paymentIntentId): PaymentIntentResult
    {
        $this->begin();
        $this->calls[] = ['method' => 'retrieve', 'key' => null, 'target' => $paymentIntentId];
        $this->interrupt('retrieve', before: true);
        $this->existing($paymentIntentId);

        return $this->result($paymentIntentId);
    }

    public function refund(string $paymentIntentId, int $amountMinor, string $currency, string $idempotencyKey, string $reason): array
    {
        $this->begin();
        $this->calls[] = ['method' => 'refund', 'key' => $idempotencyKey, 'target' => $paymentIntentId];
        $behaviour = $this->interrupt('refund', before: true);
        $refund = $this->remember($idempotencyKey, 'refund:'.$paymentIntentId.':'.$amountMinor, function () use ($paymentIntentId, $amountMinor): array {
            $intent = $this->existing($paymentIntentId);
            if ($intent['status'] !== 'succeeded') {
                throw new PaymentRejected('payment_intent_unexpected_state');
            }
            if ($amountMinor < 1 || $intent['amount_refunded'] + $amountMinor > $intent['amount_received']) {
                throw new PaymentRejected('charge_already_refunded');
            }
            $this->intents[$paymentIntentId]['amount_refunded'] += $amountMinor;
            $row = ['id' => 're_test_'.(++$this->sequence).'_'.bin2hex(random_bytes(4)), 'payment_intent' => $paymentIntentId, 'amount_minor' => $amountMinor];
            $this->refunds[] = $row;

            return ['id' => $row['id'], 'status' => 'succeeded', 'amount_minor' => $amountMinor];
        });
        $this->interrupt('refund', before: false, behaviour: $behaviour);
        $this->commit();

        return $refund;
    }

    /**
     * A Stripe-shaped webhook event for an intent, with Stripe amounts and a lowercase currency.
     *
     * @param  array<string, mixed>  $overrides  merged into data.object
     * @return array<string, mixed>
     */
    public function event(string $type, string $paymentIntentId, array $overrides = [], bool $livemode = false): array
    {
        $intent = $this->existing($paymentIntentId);
        $object = [
            'id' => $paymentIntentId,
            'object' => 'payment_intent',
            'status' => $intent['status'],
            'amount' => Currency::toStripe($intent['amount'], $intent['currency']),
            'amount_capturable' => Currency::toStripe($intent['amount_capturable'], $intent['currency']),
            'amount_received' => Currency::toStripe($intent['amount_received'], $intent['currency']),
            'currency' => strtolower($intent['currency']),
            'metadata' => $intent['metadata'],
            'latest_charge' => $intent['latest_charge'],
            'livemode' => $livemode,
            'last_payment_error' => $intent['failure_code'] !== null ? ['code' => $intent['failure_code']] : null,
        ];
        if (str_starts_with($type, 'charge.dispute.')) {
            $object = [
                'id' => 'dp_test_'.substr($paymentIntentId, 3),
                'object' => 'dispute',
                'payment_intent' => $paymentIntentId,
                'charge' => $intent['latest_charge'],
                'amount' => Currency::toStripe($intent['amount_received'], $intent['currency']),
                'currency' => strtolower($intent['currency']),
                'status' => $type === 'charge.dispute.closed' ? 'lost' : 'needs_response',
                'reason' => 'fraudulent',
                'livemode' => $livemode,
            ];
        } elseif (str_starts_with($type, 'charge.')) {
            $object = [
                'id' => $intent['latest_charge'] ?? 'ch_'.substr($paymentIntentId, 3),
                'object' => 'charge',
                'payment_intent' => $paymentIntentId,
                'amount' => Currency::toStripe($intent['amount_received'], $intent['currency']),
                'amount_captured' => Currency::toStripe($intent['amount_received'], $intent['currency']),
                'amount_refunded' => Currency::toStripe($intent['amount_refunded'], $intent['currency']),
                'refunded' => $intent['amount_refunded'] > 0 && $intent['amount_refunded'] >= $intent['amount_received'],
                'currency' => strtolower($intent['currency']),
                'metadata' => $intent['metadata'],
                'livemode' => $livemode,
            ];
        }

        return [
            'id' => 'evt_test_'.(++$this->events).'_'.substr(hash('sha256', $type.$paymentIntentId.microtime()), 0, 8),
            'object' => 'event',
            'type' => $type,
            'livemode' => $livemode,
            'created' => time(),
            'data' => ['object' => array_replace($object, $overrides)],
        ];
    }

    /** @return array<string, mixed> */
    private function existing(string $paymentIntentId): array
    {
        if (! isset($this->intents[$paymentIntentId])) {
            throw new PaymentRejected('resource_missing');
        }

        return $this->intents[$paymentIntentId];
    }

    private function result(string $paymentIntentId): PaymentIntentResult
    {
        $intent = $this->intents[$paymentIntentId];

        return new PaymentIntentResult(
            id: $paymentIntentId,
            status: $intent['status'],
            amount: $intent['amount'],
            amountCapturable: $intent['amount_capturable'],
            amountReceived: $intent['amount_received'],
            currency: $intent['currency'],
            clientSecret: in_array($intent['status'], ['requires_action', 'requires_confirmation'], true) ? $paymentIntentId.'_secret_test' : null,
            failureCode: $intent['failure_code'],
            metadata: $intent['metadata'],
            latestChargeId: $intent['latest_charge'],
        );
    }

    /**
     * Stripe idempotency: the first call with a key stores its result (errors included); later calls
     * replay it. A different request under the same key is refused.
     *
     * @template T
     *
     * @param  callable(): T  $perform
     * @return T
     */
    private function remember(string $key, string $fingerprint, callable $perform): mixed
    {
        if (isset($this->replays[$key])) {
            if ($this->replays[$key]['fingerprint'] !== $fingerprint) {
                throw new PaymentGatewayUnavailable('Idempotency key reused with different parameters.');
            }
            $stored = $this->replays[$key]['result'];
            if ($stored instanceof Throwable) {
                throw $stored;
            }

            return $stored;
        }
        try {
            $result = $perform();
        } catch (PaymentRejected $rejected) {
            $this->replays[$key] = ['fingerprint' => $fingerprint, 'result' => $rejected];
            throw $rejected;
        }
        $this->replays[$key] = ['fingerprint' => $fingerprint, 'result' => $result];

        return $result;
    }

    /** Applies a scripted interruption; returns the shaping behaviour (decline, requires_action) if any. */
    private function interrupt(string $method, bool $before, ?string $behaviour = null): ?string
    {
        if ($before) {
            $behaviour = isset($this->script[$method]) && $this->script[$method] !== [] ? array_shift($this->script[$method]) : null;
            if ($behaviour === 'timeout_before') {
                throw new PaymentOutcomeUnknown('Fake Stripe: request lost before it arrived.');
            }
            if ($behaviour === 'unavailable') {
                throw new PaymentGatewayUnavailable('Fake Stripe: unavailable.');
            }
            if ($behaviour === 'explode') {
                throw new RuntimeException('Fake Stripe: a bug, not a payment outcome.');
            }

            return $behaviour;
        }
        if ($behaviour === 'timeout_after') {
            throw new PaymentOutcomeUnknown('Fake Stripe: applied, but the response was lost.');
        }

        return $behaviour;
    }

    /** Reloads intents written by another PHP process (the scheduler, or the HTTP server). */
    private function begin(): void
    {
        if (! $this->shared) {
            return;
        }
        $stored = Cache::get(self::CACHE_KEY);
        if (! is_array($stored)) {
            return;
        }
        $this->intents = is_array($stored['intents'] ?? null) ? $stored['intents'] : $this->intents;
        $this->setupIntents = is_array($stored['setupIntents'] ?? null) ? $stored['setupIntents'] : $this->setupIntents;
        $this->paymentMethods = is_array($stored['paymentMethods'] ?? null) ? $stored['paymentMethods'] : $this->paymentMethods;
        $this->refunds = is_array($stored['refunds'] ?? null) ? $stored['refunds'] : $this->refunds;
        $this->sequence = (int) ($stored['sequence'] ?? $this->sequence);
    }

    private function commit(): void
    {
        if (! $this->shared) {
            return;
        }
        Cache::put(self::CACHE_KEY, [
            'intents' => $this->intents,
            'setupIntents' => $this->setupIntents,
            'paymentMethods' => $this->paymentMethods,
            'refunds' => $this->refunds,
            'sequence' => $this->sequence,
        ], now()->addDays(7));
    }
}

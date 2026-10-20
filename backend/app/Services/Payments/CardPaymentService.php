<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Saved cards are Stripe payment-method ids plus brand and last4. Never a PAN.
 *
 * A card is saved only after Stripe confirms it: the SetupIntent succeeded and the payment method is
 * attached to THIS user's Stripe customer. A payment-method id from another account is refused, and a
 * row belonging to another user is never touched.
 */
final class CardPaymentService
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    /** @return array<string, mixed> */
    public function summary(User $user): array
    {
        $method = DB::table('saved_payment_methods')->where('user_id', $user->id)->where('is_default', true)->first();

        return [
            'has_default' => $method !== null,
            'summary' => $method === null ? null : [
                'brand' => $method->brand,
                'last4' => $method->last4,
                'exp_month' => $method->exp_month,
                'exp_year' => $method->exp_year,
                'wallet' => null,
            ],
            'publishable_key' => config('services.stripe.key'),
        ];
    }

    /**
     * A SetupIntent for the app's PaymentSheet. The client secret is in this live response only: a
     * replay of the same Idempotency-Key returns it as null (EnforceIdempotency never stores secrets),
     * and the app then starts a new setup with a new key.
     *
     * @return array{id: string, client_secret: string, publishable_key: string|null}
     */
    public function beginSetup(User $user, string $idempotencyKey): array
    {
        $intent = $this->call(fn () => $this->gateway->createSetupIntent($this->customer($user), 'setup:'.$user->id.':'.$idempotencyKey));
        $publishable = config('services.stripe.key');

        return $intent + ['publishable_key' => is_string($publishable) && $publishable !== '' ? $publishable : null];
    }

    /** @return array<string, mixed> */
    public function confirmSetup(User $user, ?string $setupIntentId, ?string $paymentMethodId): array
    {
        $customer = DB::table('users')->where('id', $user->id)->value('stripe_customer_id');
        if (! is_string($customer) || $customer === '') {
            throw ApiException::setupInvalid();
        }
        if ($setupIntentId !== null) {
            $setup = $this->call(fn () => $this->gateway->retrieveSetupIntent($setupIntentId));
            if ($setup === null || $setup['status'] !== 'succeeded' || $setup['customer'] !== $customer || $setup['payment_method'] === null) {
                throw ApiException::setupInvalid();
            }
            if ($paymentMethodId !== null && $paymentMethodId !== $setup['payment_method']) {
                throw ApiException::setupInvalid();
            }
            $paymentMethodId = $setup['payment_method'];
        }
        if ($paymentMethodId === null) {
            throw ApiException::setupInvalid();
        }
        $card = $this->call(fn () => $this->gateway->paymentMethod($paymentMethodId));
        if ($card === null || $card['customer'] !== $customer || preg_match('/^[0-9]{4}$/', $card['last4']) !== 1) {
            throw ApiException::setupInvalid();
        }

        DB::transaction(function () use ($user, $paymentMethodId, $card): void {
            DB::table('users')->where('id', $user->id)->lockForUpdate()->first(['id']);
            $existing = DB::table('saved_payment_methods')->where('stripe_payment_method_id', $paymentMethodId)->lockForUpdate()->first();
            if ($existing !== null && (string) $existing->user_id !== (string) $user->id) {
                throw ApiException::setupInvalid();
            }
            DB::table('saved_payment_methods')->where('user_id', $user->id)->where('is_default', true)->update(['is_default' => false]);
            $values = [
                'brand' => mb_substr($card['brand'], 0, 40),
                'last4' => $card['last4'],
                'exp_month' => $card['exp_month'],
                'exp_year' => $card['exp_year'],
                'is_default' => true,
            ];
            if ($existing === null) {
                DB::table('saved_payment_methods')->insert($values + [
                    'id' => (string) Str::uuid7(),
                    'user_id' => $user->id,
                    'stripe_payment_method_id' => $paymentMethodId,
                    'created_at' => now(),
                ]);
            } else {
                DB::table('saved_payment_methods')->where('id', $existing->id)->update($values);
            }
        });

        return $this->summary($user);
    }

    /** The buyer's default card, or PAYMENT_METHOD_REQUIRED before anything is reserved. */
    public function defaultMethodId(User $user): string
    {
        $method = DB::table('saved_payment_methods')->where('user_id', $user->id)->where('is_default', true)->value('stripe_payment_method_id');
        if (! is_string($method) || $method === '') {
            throw ApiException::paymentMethodRequired();
        }

        return $method;
    }

    /**
     * The user's Stripe customer, created once. The idempotency key is the user id, so two concurrent
     * first payments resolve to the same customer instead of creating two.
     */
    public function customer(User $user): string
    {
        $existing = DB::table('users')->where('id', $user->id)->value('stripe_customer_id');
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }
        $created = $this->call(fn () => $this->gateway->createCustomer((string) $user->id, (string) $user->email, 'customer:'.$user->id));
        DB::table('users')->where('id', $user->id)->whereNull('stripe_customer_id')->update(['stripe_customer_id' => $created]);

        return (string) DB::table('users')->where('id', $user->id)->value('stripe_customer_id');
    }

    /**
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    private function call(callable $call): mixed
    {
        try {
            return $call();
        } catch (PaymentOutcomeUnknown|PaymentGatewayUnavailable|PaymentRejected) {
            throw ApiException::paymentsUnavailable();
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Payments;

/**
 * Card payments through Stripe PaymentIntents with manual capture. Implementations never accept or
 * return a PAN or CVC.
 *
 * Every mutating call takes an idempotency key derived from our own ids (order:{id}:authorize, ...),
 * so a retry after a timeout replays Stripe's stored result instead of charging twice.
 *
 * Errors
 *  - PaymentOutcomeUnknown: the request may have been applied (timeout, 5xx): retry with the same key;
 *  - PaymentGatewayUnavailable: it was not applied (not configured, rate limited, bad credentials);
 *  - PaymentRejected: Stripe refused for a definite reason (refunds);
 *  - a declined card is not an exception: authorize() returns status requires_payment_method with
 *    failureCode set.
 */
interface PaymentGateway
{
    public function createCustomer(string $userId, string $email, string $idempotencyKey): string;

    /** @return array{id: string, client_secret: string} */
    public function createSetupIntent(string $customerId, string $idempotencyKey): array;

    /** @return array{id: string, status: string, customer: string|null, payment_method: string|null}|null */
    public function retrieveSetupIntent(string $setupIntentId): ?array;

    /** @return array{id: string, customer: string|null, brand: string, last4: string, exp_month: int|null, exp_year: int|null}|null */
    public function paymentMethod(string $paymentMethodId): ?array;

    /** Detach a card from its customer. Already-detached cards are a no-op. */
    public function detachPaymentMethod(string $paymentMethodId): void;

    /** True only for the local fake gateway. Real Stripe must confirm the card in the app. */
    public function supportsTestCards(): bool;

    public function authorize(AuthorizeRequest $request): PaymentIntentResult;

    /** Captures $amountMinor (null = everything authorized). Partial capture releases the rest. */
    public function capture(string $paymentIntentId, ?int $amountMinor, string $currency, string $idempotencyKey): PaymentIntentResult;

    public function cancel(string $paymentIntentId, string $idempotencyKey): PaymentIntentResult;

    public function retrieve(string $paymentIntentId): PaymentIntentResult;

    /** @return array{id: string, status: string, amount_minor: int} */
    public function refund(string $paymentIntentId, int $amountMinor, string $currency, string $idempotencyKey, string $reason): array;
}

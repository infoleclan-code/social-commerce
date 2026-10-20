<?php

declare(strict_types=1);

namespace App\Services\Payments;

/**
 * Where an order's payment stands after a call, and what the app must do next.
 *
 * payment_status: pending (outcome unknown, poll), requires_action (run next_action in the Stripe SDK),
 * authorized (hold placed; captured after the undo window), captured, failed, voided, refunded, ...
 * The client secret is passed through from Stripe for this response only; it is never stored.
 */
final readonly class PaymentStep
{
    /** @param  array{type: string, client_secret: string}|null  $nextAction */
    public function __construct(
        public string $paymentStatus,
        public ?array $nextAction = null,
        public ?string $failureCode = null,
    ) {}

    /** @return array{payment_status: string, next_action: array{type: string, client_secret: string}|null, failure_code: string|null} */
    public function toArray(): array
    {
        return [
            'payment_status' => $this->paymentStatus,
            'next_action' => $this->nextAction,
            'failure_code' => $this->failureCode,
        ];
    }
}

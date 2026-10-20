<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Support\Currency;

/**
 * The parts of a Stripe PaymentIntent this app acts on. Amounts are minor units of $currency.
 * Never carries card details; the client secret is kept only while the buyer must act (3-D Secure).
 */
final readonly class PaymentIntentResult
{
    /** @param  array<string, string>  $metadata */
    public function __construct(
        public ?string $id,
        public string $status,
        public int $amount,
        public int $amountCapturable,
        public int $amountReceived,
        public string $currency,
        public ?string $clientSecret = null,
        public ?string $failureCode = null,
        public array $metadata = [],
        public ?string $latestChargeId = null,
        public bool $livemode = false,
    ) {}

    public function isAuthorized(): bool
    {
        return $this->status === 'requires_capture';
    }

    public function isCaptured(): bool
    {
        return $this->status === 'succeeded';
    }

    public function needsAction(): bool
    {
        return in_array($this->status, ['requires_action', 'requires_confirmation'], true);
    }

    public function isCanceled(): bool
    {
        return $this->status === 'canceled';
    }

    /** Declined, or the saved card can no longer be used: nothing will be charged. */
    public function isFailed(): bool
    {
        return $this->status === 'requires_payment_method';
    }

    /** Stripe has not decided yet (processing); ask again later. */
    public function isUndecided(): bool
    {
        return $this->status === 'processing';
    }

    /**
     * Builds the result from a PaymentIntent payload (an API response or a webhook's data.object).
     *
     * @param  array<string, mixed>  $intent
     */
    public static function fromArray(array $intent): self
    {
        $currency = strtoupper((string) ($intent['currency'] ?? ''));
        $error = is_array($intent['last_payment_error'] ?? null) ? $intent['last_payment_error'] : null;
        $metadata = [];
        foreach ((array) ($intent['metadata'] ?? []) as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $metadata[$key] = (string) $value;
            }
        }
        $status = (string) ($intent['status'] ?? '');
        $charge = $intent['latest_charge'] ?? null;
        $toMinor = fn (mixed $value): int => $currency === '' ? (int) $value : Currency::fromStripe((int) $value, $currency);

        return new self(
            id: isset($intent['id']) && is_string($intent['id']) ? $intent['id'] : null,
            status: $status,
            amount: $toMinor($intent['amount'] ?? 0),
            amountCapturable: $toMinor($intent['amount_capturable'] ?? 0),
            amountReceived: $toMinor($intent['amount_received'] ?? 0),
            currency: $currency,
            clientSecret: in_array($status, ['requires_action', 'requires_confirmation'], true) && is_string($intent['client_secret'] ?? null)
                ? $intent['client_secret'] : null,
            failureCode: $error === null ? null : (string) ($error['decline_code'] ?? $error['code'] ?? 'card_declined'),
            metadata: $metadata,
            latestChargeId: is_string($charge) ? $charge : (is_array($charge) && is_string($charge['id'] ?? null) ? $charge['id'] : null),
            livemode: (bool) ($intent['livemode'] ?? false),
        );
    }
}

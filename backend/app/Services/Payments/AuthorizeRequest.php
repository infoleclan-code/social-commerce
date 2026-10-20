<?php

declare(strict_types=1);

namespace App\Services\Payments;

/** One manual-capture authorization. The idempotency key is stable per order, so a retry is a replay. */
final readonly class AuthorizeRequest
{
    /** @param  array<string, string>  $metadata */
    public function __construct(
        public string $customerId,
        public string $paymentMethodId,
        public int $amountMinor,
        public string $currency,
        public string $idempotencyKey,
        public array $metadata,
    ) {}
}

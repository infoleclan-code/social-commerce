<?php

declare(strict_types=1);

namespace App\Services\Payments;

/** Stripe Connect account creation. No bank account numbers are stored here. */
interface SellerPayouts
{
    public function createExpressAccount(string $email, string $country, string $idempotencyKey): string;

    public function onboardingUrl(string $accountId): string;

    /** @return array{charges_enabled: bool, payouts_enabled: bool, details_submitted: bool} */
    public function status(string $accountId): array;
}

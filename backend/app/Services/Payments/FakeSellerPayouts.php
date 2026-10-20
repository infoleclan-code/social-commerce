<?php

declare(strict_types=1);

namespace App\Services\Payments;

final class FakeSellerPayouts implements SellerPayouts
{
    public function createExpressAccount(string $email, string $country, string $idempotencyKey): string
    {
        return 'acct_test';
    }

    public function onboardingUrl(string $accountId): string
    {
        return 'https://connect.stripe.com/setup/test';
    }

    public function status(string $accountId): array
    {
        return ['charges_enabled' => true, 'payouts_enabled' => true, 'details_submitted' => true];
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Exceptions\ApiException;
use Stripe\ApiRequestor;
use Stripe\HttpClient\CurlClient;
use Stripe\StripeClient;

final class StripeSellerPayouts implements SellerPayouts
{
    public function __construct(private readonly string $secret) {}

    public function createExpressAccount(string $email, string $country, string $idempotencyKey): string
    {
        $account = $this->client()->accounts->create([
            'type' => 'express',
            'email' => $email,
            'country' => $country,
            'capabilities' => [
                'card_payments' => ['requested' => true],
                'transfers' => ['requested' => true],
            ],
        ], ['idempotency_key' => $idempotencyKey]);

        return (string) $account->id;
    }

    public function onboardingUrl(string $accountId): string
    {
        $base = rtrim((string) config('app.url'), '/');
        $link = $this->client()->accountLinks->create([
            'account' => $accountId,
            'refresh_url' => $base.'/sell',
            'return_url' => $base.'/sell',
            'type' => 'account_onboarding',
        ]);

        return (string) $link->url;
    }

    public function status(string $accountId): array
    {
        $account = $this->client()->accounts->retrieve($accountId);

        return [
            'charges_enabled' => (bool) $account->charges_enabled,
            'payouts_enabled' => (bool) $account->payouts_enabled,
            'details_submitted' => (bool) $account->details_submitted,
        ];
    }

    private function client(): StripeClient
    {
        if ($this->secret === '') {
            throw ApiException::paymentsUnavailable();
        }

        $http = ApiRequestor::httpClient();
        if ($http instanceof CurlClient) {
            $http->setTimeout((int) config('services.stripe.timeout_seconds', 20));
            $http->setConnectTimeout((int) config('services.stripe.connect_timeout_seconds', 5));
        }

        return new StripeClient(['api_key' => $this->secret, 'max_network_retries' => (int) config('services.stripe.max_network_retries', 2)]);
    }
}

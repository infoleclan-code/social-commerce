<?php

declare(strict_types=1);

namespace Tests\Support;

use Stripe\Exception\ApiConnectionException;
use Stripe\HttpClient\ClientInterface;
use Throwable;

/**
 * Stands in for Stripe's HTTP transport, so StripePaymentGateway is tested through the real SDK:
 * request encoding, headers (Idempotency-Key), error parsing and exception classes are Stripe's own.
 */
final class FakeStripeHttpClient implements ClientInterface
{
    /** @var list<array{method: string, url: string, headers: list<string>, params: array<string, mixed>}> */
    public array $requests = [];

    /** @var list<array{0: int, 1: array<string, mixed>}|Throwable> */
    private array $responses = [];

    /** @param  array<string, mixed>  $body */
    public function reply(int $status, array $body): self
    {
        $this->responses[] = [$status, $body];

        return $this;
    }

    public function fail(Throwable $error): self
    {
        $this->responses[] = $error;

        return $this;
    }

    public function timeout(): self
    {
        return $this->fail(new ApiConnectionException('Could not connect to Stripe (timed out).'));
    }

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->requests[] = ['method' => $method, 'url' => $absUrl, 'headers' => $headers, 'params' => $params];
        $next = array_shift($this->responses) ?? [500, ['error' => ['type' => 'api_error', 'message' => 'No scripted response']]];
        if ($next instanceof Throwable) {
            throw $next;
        }

        return [json_encode($next[1], JSON_THROW_ON_ERROR), $next[0], ['request-id' => ['req_test']]];
    }

    public function header(int $request, string $name): ?string
    {
        foreach ($this->requests[$request]['headers'] ?? [] as $line) {
            if (stripos($line, $name.':') === 0) {
                return trim(substr($line, strlen($name) + 1));
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public static function intent(string $status, int $amount = 2500, string $currency = 'usd', array $extra = []): array
    {
        return $extra + [
            'id' => 'pi_test_123',
            'object' => 'payment_intent',
            'status' => $status,
            'amount' => $amount,
            'amount_capturable' => $status === 'requires_capture' ? $amount : 0,
            'amount_received' => $status === 'succeeded' ? $amount : 0,
            'currency' => $currency,
            'client_secret' => 'pi_test_123_secret_abc',
            'metadata' => ['order_id' => 'order-1'],
            'livemode' => false,
            'latest_charge' => $status === 'succeeded' ? 'ch_test_1' : null,
            'last_payment_error' => null,
        ];
    }
}

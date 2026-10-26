<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\SentryReporter;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

final class SentryReporterTest extends TestCase
{
    public function test_the_envelope_includes_an_event_id_and_a_timestamp(): void
    {
        config(['sentry.dsn' => 'https://public-key@o123.ingest.sentry.io/456']);
        Http::fake();

        app(SentryReporter::class)->capture(new RuntimeException('mail user@example.com failed'));

        Http::assertSent(function ($request): bool {
            $body = $request->body();
            $lines = explode("\n", $body);
            $header = json_decode($lines[0] ?? '', true);
            $event = json_decode($lines[2] ?? '', true);

            return $request->url() === 'https://o123.ingest.sentry.io/api/456/envelope/'
                && is_array($header)
                && preg_match('/^[a-f0-9]{32}$/', (string) ($header['event_id'] ?? '')) === 1
                && is_string($header['sent_at'] ?? null)
                && is_array($event)
                && $event['event_id'] === $header['event_id']
                && is_int($event['timestamp'])
                && ! str_contains($body, 'user@example.com');
        });
    }

    public function test_an_empty_dsn_sends_nothing(): void
    {
        config(['sentry.dsn' => '']);
        Http::fake();

        app(SentryReporter::class)->capture(new RuntimeException('ignored'));

        Http::assertNothingSent();
    }
}

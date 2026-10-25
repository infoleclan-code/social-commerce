<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Posts an exception to Sentry when SENTRY_LARAVEL_DSN is set. Empty DSN is a no-op.
 * The body is a Sentry envelope (event_id, sent_at, and a timestamped event).
 * Email-shaped text is removed. A transport failure does not affect the request.
 */
final class SentryReporter
{
    public function capture(Throwable $exception): void
    {
        $dsn = (string) config('sentry.dsn');
        if ($dsn === '') {
            return;
        }
        $parts = parse_url($dsn);
        $publicKey = $parts['user'] ?? '';
        $host = $parts['host'] ?? '';
        $project = trim((string) ($parts['path'] ?? ''), '/');
        if ($publicKey === '' || $host === '' || $project === '') {
            return;
        }
        $scheme = $parts['scheme'] ?? 'https';
        $message = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email]', $exception->getMessage()) ?? '';
        $eventId = str_replace('-', '', (string) Str::uuid());
        $sentAt = gmdate('Y-m-d\TH:i:s\Z');
        $header = json_encode([
            'event_id' => $eventId,
            'sent_at' => $sentAt,
        ], JSON_THROW_ON_ERROR);
        $item = json_encode(['type' => 'event', 'content_type' => 'application/json'], JSON_THROW_ON_ERROR);
        $payload = json_encode([
            'event_id' => $eventId,
            'timestamp' => time(),
            'platform' => 'php',
            'level' => 'error',
            'exception' => [
                'values' => [[
                    'type' => $exception::class,
                    'value' => mb_substr($message, 0, 300),
                ]],
            ],
        ], JSON_THROW_ON_ERROR);
        $body = $header."\n".$item."\n".$payload;

        try {
            Http::timeout(2)
                ->withHeaders([
                    'X-Sentry-Auth' => 'Sentry sentry_version=7, sentry_client=social/1.0, sentry_key='.$publicKey,
                ])
                ->withBody($body, 'application/x-sentry-envelope')
                ->post("{$scheme}://{$host}/api/{$project}/envelope/");
        } catch (Throwable) {
            // Error tracking must not take the request down with it.
        }
    }
}

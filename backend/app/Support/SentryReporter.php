<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Posts an exception to Sentry when SENTRY_LARAVEL_DSN is set. Empty DSN is a no-op.
 * The payload is the exception class and a short message with email-shaped text removed.
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

        try {
            Http::timeout(2)->withHeaders([
                'X-Sentry-Auth' => 'Sentry sentry_version=7, sentry_client=social/1.0, sentry_key='.$publicKey,
            ])->post("{$scheme}://{$host}/api/{$project}/store/", [
                'message' => mb_substr($exception::class.': '.$message, 0, 400),
                'level' => 'error',
                'platform' => 'php',
                'exception' => [
                    'values' => [[
                        'type' => $exception::class,
                        'value' => mb_substr($message, 0, 300),
                    ]],
                ],
            ]);
        } catch (Throwable) {
            // Error tracking must not take the request down with it.
        }
    }
}

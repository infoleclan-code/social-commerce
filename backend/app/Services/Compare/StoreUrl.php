<?php

declare(strict_types=1);

namespace App\Services\Compare;

/** An https store page. API hosts, Google wrappers, and IP addresses are not merchant links. */
final class StoreUrl
{
    /** @var list<string> */
    private const BLOCKED = [
        'localhost',
        'serpapi.com',
        'google.com',
        'gstatic.com',
        'googleusercontent.com',
        'googleadservices.com',
    ];

    public static function merchant(string $url): ?string
    {
        if (! str_starts_with($url, 'https://')) {
            return null;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '' || ! str_contains($host, '.') || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }
        foreach (self::BLOCKED as $blocked) {
            if ($host === $blocked || str_ends_with($host, '.'.$blocked)) {
                return null;
            }
        }

        return $url;
    }
}

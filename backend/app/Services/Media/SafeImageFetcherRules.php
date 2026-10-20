<?php

declare(strict_types=1);

namespace App\Services\Media;

/** The URL rules of SafeImageFetcher without DNS, shared with the test double. */
final class SafeImageFetcherRules
{
    public function assertUrl(string $url): void
    {
        $parts = parse_url($url);
        if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'https' || ! isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && (int) $parts['port'] !== 443)
            || filter_var(trim((string) $parts['host'], '[]'), FILTER_VALIDATE_IP) !== false) {
            throw new ImageFetchFailed('The image URL is not allowed.');
        }
    }
}

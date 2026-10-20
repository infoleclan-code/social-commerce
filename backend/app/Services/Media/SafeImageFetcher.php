<?php

declare(strict_types=1);

namespace App\Services\Media;

use Closure;

/**
 * Fetches seller-supplied image URLs without becoming an SSRF proxy.
 *
 *  - https on port 443 only; no credentials in the URL; no redirects (a redirect could point inside);
 *  - the host is resolved once, every address must be globally routable (no loopback, RFC 1918,
 *    link-local 169.254.0.0/16 metadata endpoints, CGNAT, ULA, ...), and the connection is pinned to
 *    the checked address with CURLOPT_RESOLVE, so DNS rebinding between check and connect fails;
 *  - bounded: connect 3 s, total 8 s, at most commerce.visual_search.max_upload_bytes, aborted
 *    mid-stream when exceeded; the response must be 200 with an image content type.
 */
final class SafeImageFetcher implements ImageFetcher
{
    /** @param  (Closure(string): list<string>)|null  $resolver  DNS lookup (injectable for tests) */
    public function __construct(
        private readonly int $connectTimeoutSeconds = 3,
        private readonly int $timeoutSeconds = 8,
        private readonly ?Closure $resolver = null,
    ) {}

    public function fetch(string $url): string
    {
        [$host, $address, $path] = $this->target($url);
        $maxBytes = (int) config('commerce.visual_search.max_upload_bytes', 8 * 1024 * 1024);
        $body = '';
        $tooLarge = false;
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => 'https://'.$host.$path,
            CURLOPT_RESOLVE => [$host.':443:'.(str_contains($address, ':') ? '['.$address.']' : $address)],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: image/jpeg, image/png, image/webp'],
            CURLOPT_USERAGENT => 'CatalogImageFetcher/1.0',
            CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$body, &$tooLarge, $maxBytes): int {
                if (strlen($body) + strlen($chunk) > $maxBytes) {
                    $tooLarge = true;

                    return 0;
                }
                $body .= $chunk;

                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $type = strtolower((string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE));
        curl_close($curl);
        if ($tooLarge) {
            throw new ImageFetchFailed('The image is larger than allowed.');
        }
        if ($ok === false || $status !== 200) {
            throw new ImageFetchFailed('The image could not be downloaded (HTTP '.$status.').');
        }
        if (! preg_match('#^image/(jpeg|png|webp)\b#', $type)) {
            throw new ImageFetchFailed('The URL did not return a JPEG, PNG or WebP image.');
        }

        return $body;
    }

    /**
     * Validates the URL and resolves the host to one public address.
     *
     * @return array{0: string, 1: string, 2: string} host, pinned address, path with query
     */
    public function target(string $url): array
    {
        if (strlen($url) > 500 || preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            throw new ImageFetchFailed('The image URL is not valid.');
        }
        $parts = parse_url($url);
        if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'https' || ! isset($parts['host'])) {
            throw new ImageFetchFailed('Image URLs must use https.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new ImageFetchFailed('Image URLs cannot carry credentials.');
        }
        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            throw new ImageFetchFailed('Image URLs must use the standard https port.');
        }
        $host = strtolower(rtrim($parts['host'], '.'));
        $literal = trim($host, '[]');
        if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
            throw new ImageFetchFailed('Image URLs must use a host name, not an IP address.');
        }
        if (preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/', $host) !== 1) {
            throw new ImageFetchFailed('The image host name is not valid.');
        }
        $addresses = $this->resolve($host);
        if ($addresses === []) {
            throw new ImageFetchFailed('The image host does not resolve.');
        }
        foreach ($addresses as $address) {
            if (! self::isPublic($address)) {
                throw new ImageFetchFailed('The image host resolves to a private address.');
            }
        }
        $path = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');

        return [$host, $addresses[0], $path === '' ? '/' : $path];
    }

    public static function isPublic(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) !== false;
    }

    /** @return list<string> */
    private function resolve(string $host): array
    {
        if ($this->resolver !== null) {
            return array_values(($this->resolver)($host));
        }
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        $addresses = [];
        foreach (is_array($records) ? $records : [] as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($address)) {
                $addresses[] = $address;
            }
        }
        if ($addresses === []) {
            $fallback = @gethostbynamel($host);
            $addresses = is_array($fallback) ? $fallback : [];
        }

        return array_values(array_unique($addresses));
    }
}

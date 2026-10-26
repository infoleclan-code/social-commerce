<?php

declare(strict_types=1);

namespace App\Services\Compare;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * A TikTok, Instagram, or YouTube link becomes a thumbnail, then the same photo search.
 * Video frames are not pulled from the platform. The page's og:image is the thumbnail.
 */
final class LinkSearch
{
    /** @var list<string> */
    private const HOSTS = ['tiktok.com', 'instagram.com', 'youtube.com', 'youtu.be'];

    public function __construct(private readonly VisualSearch $visual) {}

    /**
     * @param  array{brand?: string|null, size?: string|null, min_minor?: int|null, max_minor?: int|null, in_stock: bool, sort: string, currency: string, country?: string|null}  $filters
     * @return array<string, mixed>
     */
    public function search(string $url, array $filters): array
    {
        $page = $this->page($url);
        if ($page === null) {
            throw ApiException::linkRejected();
        }
        try {
            $html = $this->body($page, true);
            $image = $this->image($html, $page);
            if ($image === null) {
                throw new RuntimeException('No thumbnail');
            }
            $bytes = $this->body($image, false);
            $mime = str_starts_with($bytes, "\x89PNG") ? 'image/png' : (str_starts_with($bytes, 'RIFF') ? 'image/webp' : 'image/jpeg');
            $result = $this->visual->search($bytes, $mime, $filters);
        } catch (ApiException $exception) {
            if ($exception->errorCode === 'LINK_REJECTED') {
                throw $exception;
            }
            throw ApiException::linkUnavailable();
        } catch (Throwable $exception) {
            report(new RuntimeException('Link search failed: '.$exception::class));
            throw ApiException::linkUnavailable();
        }
        $result['source_host'] = (string) parse_url($page, PHP_URL_HOST);

        return $result;
    }

    private function page(string $url): ?string
    {
        $url = trim($url);
        if (strlen($url) > 500 || preg_match('#^https://#i', $url) !== 1) {
            return null;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host) ?? $host;
        if (! $this->social($host) || $this->blockedHost($host)) {
            return null;
        }

        return $url;
    }

    private function social(string $host): bool
    {
        foreach (self::HOSTS as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }

    private function image(string $html, string $page): ?string
    {
        $patterns = [
            '/<meta[^>]+property=["\']og:image(?::secure_url)?["\'][^>]+content=["\']([^"\']+)["\']/i',
            '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image(?::secure_url)?["\']/i',
            '/<meta[^>]+name=["\']twitter:image["\'][^>]+content=["\']([^"\']+)["\']/i',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $match) === 1) {
                return $this->absolute(html_entity_decode($match[1], ENT_QUOTES), $page);
            }
        }

        return null;
    }

    private function absolute(string $value, string $page): ?string
    {
        if (str_starts_with($value, 'https://')) {
            return $this->publicImage($value) ? $value : null;
        }
        if (str_starts_with($value, '//')) {
            $value = 'https:'.$value;

            return $this->publicImage($value) ? $value : null;
        }
        $base = (string) parse_url($page, PHP_URL_SCHEME).'://'.(string) parse_url($page, PHP_URL_HOST);
        if (str_starts_with($value, '/')) {
            $value = $base.$value;

            return $this->publicImage($value) ? $value : null;
        }

        return null;
    }

    private function publicImage(string $url): bool
    {
        if (preg_match('#^https://#i', $url) !== 1) {
            return false;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $host !== '' && ! $this->blockedHost($host);
    }

    private function blockedHost(string $host): bool
    {
        if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return true;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    private function body(string $url, bool $page): string
    {
        $current = $url;
        for ($hop = 0; $hop < 3; $hop++) {
            $response = Http::timeout(8)->withOptions(['allow_redirects' => false])->get($current);
            if (in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                $next = $this->absolute((string) $response->header('Location'), $current);
                if ($next === null) {
                    throw new RuntimeException('Redirect left the allowed hosts');
                }
                if ($page) {
                    $host = strtolower((string) parse_url($next, PHP_URL_HOST));
                    $host = preg_replace('/^www\./', '', $host) ?? $host;
                    if (! $this->social($host)) {
                        throw new RuntimeException('Redirect left the social host');
                    }
                }
                $current = $next;

                continue;
            }
            if (! $response->successful()) {
                throw new RuntimeException('Page was not available');
            }
            $body = $response->body();
            $limit = $page ? 1_000_000 : 500_000;
            if (strlen($body) > $limit) {
                throw new RuntimeException('Response was too large');
            }

            return $body;
        }

        throw new RuntimeException('Too many redirects');
    }
}

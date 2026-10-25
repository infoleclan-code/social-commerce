<?php

declare(strict_types=1);

namespace App\Services\Compare;

use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Ranks a provider result into product groups and keeps at most three in-stock
 * offers from different stores. Expired cache is never returned. A provider
 * failure is a 503, including when an older payload is still sitting in cache.
 */
final class CompareService
{
    public function __construct(private readonly OfferProvider $provider) {}

    /**
     * @param  array{brand?: string|null, size?: string|null, min_minor?: int|null, max_minor?: int|null, in_stock: bool, sort: string, currency: string}  $filters
     * @return array<string, mixed>
     */
    public function search(string $query, array $filters): array
    {
        return $this->present($query, $this->fresh($query), $filters);
    }

    public function cacheKey(string $query): string
    {
        return 'compare:'.hash('sha256', mb_strtolower(trim($query)).'|'.(string) config('compare.provider'));
    }

    /** @return array{url: string} */
    public function click(User $user, string $offerId): array
    {
        $offer = Cache::get($this->offerCacheKey($offerId));
        if (! is_array($offer) || ! is_string($offer['url'] ?? null)) {
            throw ApiException::offerExpired();
        }

        $url = $this->affiliate($offer['url']);
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '' || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw ApiException::offerExpired();
        }

        DB::table('outbound_clicks')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $user->id,
            'store' => mb_substr((string) ($offer['store'] ?? 'Store'), 0, 120),
            'host' => mb_substr($host, 0, 190),
            'offer_key' => $offerId,
            'created_at' => now(),
        ]);

        return ['url' => $url];
    }

    /** @return array{checked_at: int, offers: list<array<string, mixed>>} */
    private function fresh(string $query): array
    {
        $ttl = max(30, (int) config('compare.cache_ttl', 600));
        $cached = Cache::get($this->cacheKey($query));
        if (
            is_array($cached)
            && isset($cached['checked_at'], $cached['offers'])
            && is_array($cached['offers'])
            && (time() - (int) $cached['checked_at']) < $ttl
        ) {
            return ['checked_at' => (int) $cached['checked_at'], 'offers' => array_values($cached['offers'])];
        }

        try {
            $offers = $this->provider->search($query);
        } catch (Throwable $exception) {
            report(new RuntimeException('Offer provider failed: '.$exception::class));
            throw ApiException::compareUnavailable();
        }

        $payload = ['checked_at' => time(), 'offers' => array_values($offers)];
        Cache::put($this->cacheKey($query), $payload, $ttl);

        return $payload;
    }

    /**
     * @param  array{checked_at: int, offers: list<array<string, mixed>>}  $payload
     * @param  array{brand?: string|null, size?: string|null, min_minor?: int|null, max_minor?: int|null, in_stock: bool, sort: string, currency: string}  $filters
     * @return array<string, mixed>
     */
    private function present(string $query, array $payload, array $filters): array
    {
        $checkedAt = gmdate('c', $payload['checked_at']);
        $currency = $filters['currency'];
        $groups = [];

        foreach ($payload['offers'] as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $offer = $this->normalize($raw, $currency, $checkedAt);
            if ($offer === null || ! $this->passes($offer, $filters)) {
                continue;
            }
            $key = strtolower($offer['brand'].'|'.$offer['model'].'|'.(string) ($offer['size'] ?? ''));
            $groups[$key][] = $offer;
        }

        $products = [];
        foreach ($groups as $offers) {
            $current = array_values(array_filter($offers, fn (array $offer): bool => $offer['availability'] === 'in_stock'));
            $unavailable = array_values(array_filter($offers, fn (array $offer): bool => $offer['availability'] !== 'in_stock'));
            $current = $this->sortOffers($current, $filters['sort']);
            $current = $this->distinctStores($current);
            $product = [
                'brand' => $offers[0]['brand'],
                'model' => $offers[0]['model'],
                'size' => $offers[0]['size'],
                'title' => $offers[0]['title'],
                'offers' => array_slice($current, 0, 3),
            ];
            if ($filters['in_stock'] === false) {
                $product['unavailable'] = $this->distinctStores($unavailable);
            }
            if ($product['offers'] === [] && ($product['unavailable'] ?? []) === []) {
                continue;
            }
            $products[] = $product;
        }

        usort($products, function (array $left, array $right): int {
            $leftTotal = $left['offers'][0]['rank'] ?? PHP_INT_MAX;
            $rightTotal = $right['offers'][0]['rank'] ?? PHP_INT_MAX;

            return $leftTotal <=> $rightTotal;
        });

        $products = array_slice($products, 0, 4);
        foreach ($products as &$product) {
            foreach ($product['offers'] as &$offer) {
                $this->publish($offer);
                unset($offer['rank'], $offer['url']);
            }
            if (isset($product['unavailable'])) {
                foreach ($product['unavailable'] as &$offer) {
                    unset($offer['rank'], $offer['url'], $offer['id']);
                }
            }
        }
        unset($product, $offer);

        return [
            'query' => $query,
            'currency' => $currency,
            'checked_at' => $checkedAt,
            'products' => $products,
        ];
    }

    /** @param  array<string, mixed>  $raw */
    private function normalize(array $raw, string $currency, string $checkedAt): ?array
    {
        $url = (string) ($raw['url'] ?? '');
        $store = trim((string) ($raw['store'] ?? ''));
        if ($store === '' || ! str_starts_with($url, 'https://') || ! is_numeric($raw['price_minor'] ?? null)) {
            return null;
        }

        $from = strtoupper((string) ($raw['currency'] ?? 'USD'));
        $price = $this->convert((int) $raw['price_minor'], $from, $currency);
        $known = (bool) ($raw['shipping_known'] ?? false);
        $shipping = $known && is_numeric($raw['shipping_minor'] ?? null)
            ? $this->convert((int) $raw['shipping_minor'], $from, $currency)
            : null;
        $total = $known && $shipping !== null ? $price + $shipping : $price;
        $id = substr(hash('sha256', implode('|', [
            strtolower($store),
            $url,
            (string) $raw['price_minor'],
            (string) ($raw['shipping_minor'] ?? ''),
            (string) ($raw['availability'] ?? ''),
        ])), 0, 24);

        return [
            'id' => $id,
            'store' => mb_substr($store, 0, 120),
            'title' => mb_substr((string) ($raw['title'] ?? $store), 0, 180),
            'brand' => mb_substr((string) ($raw['brand'] ?? $store), 0, 80),
            'model' => mb_substr((string) ($raw['model'] ?? $raw['title'] ?? $store), 0, 120),
            'size' => isset($raw['size']) && $raw['size'] !== null && $raw['size'] !== '' ? mb_substr((string) $raw['size'], 0, 40) : null,
            'price_minor' => $price,
            'shipping_minor' => $shipping,
            'shipping_known' => $known,
            'total_minor' => $total,
            'total_known' => $known,
            'currency' => $currency,
            'availability' => ($raw['availability'] ?? '') === 'out_of_stock' ? 'out_of_stock' : 'in_stock',
            'checked_at' => $checkedAt,
            'url' => $url,
            'rank' => $known ? $total : $price + 1_000_000_000,
        ];
    }

    /** @param  array<string, mixed>  $offer */
    private function passes(array $offer, array $filters): bool
    {
        if ($filters['in_stock'] === true && $offer['availability'] !== 'in_stock') {
            return false;
        }
        $brand = strtolower(trim((string) ($filters['brand'] ?? '')));
        if ($brand !== '' && strtolower($offer['brand']) !== $brand) {
            return false;
        }
        $size = strtolower(trim((string) ($filters['size'] ?? '')));
        if ($size !== '' && strtolower((string) ($offer['size'] ?? '')) !== $size) {
            return false;
        }
        $amount = $offer['shipping_known'] ? $offer['total_minor'] : $offer['price_minor'];
        if (isset($filters['min_minor']) && $filters['min_minor'] !== null && $amount < $filters['min_minor']) {
            return false;
        }
        if (isset($filters['max_minor']) && $filters['max_minor'] !== null && $amount > $filters['max_minor']) {
            return false;
        }

        return true;
    }

    /**
     * @param  list<array<string, mixed>>  $offers
     * @return list<array<string, mixed>>
     */
    private function sortOffers(array $offers, string $sort): array
    {
        usort($offers, function (array $left, array $right) use ($sort): int {
            $compared = match ($sort) {
                'total_desc' => $right['rank'] <=> $left['rank'],
                'price_asc' => $left['price_minor'] <=> $right['price_minor'],
                default => $left['rank'] <=> $right['rank'],
            };

            return $compared !== 0 ? $compared : strcmp($left['store'], $right['store']);
        });

        return $offers;
    }

    /**
     * @param  list<array<string, mixed>>  $offers
     * @return list<array<string, mixed>>
     */
    private function distinctStores(array $offers): array
    {
        $seen = [];
        $unique = [];
        foreach ($offers as $offer) {
            $store = strtolower($offer['store']);
            if (isset($seen[$store])) {
                continue;
            }
            $seen[$store] = true;
            $unique[] = $offer;
        }

        return $unique;
    }

    /** @param  array<string, mixed>  $offer */
    private function publish(array $offer): void
    {
        Cache::put($this->offerCacheKey((string) $offer['id']), [
            'store' => $offer['store'],
            'url' => $offer['url'],
        ], max(30, (int) config('compare.cache_ttl', 600)));
    }

    private function offerCacheKey(string $offerId): string
    {
        return 'compare-offer:'.$offerId;
    }

    private function affiliate(string $url): string
    {
        $id = (string) config('compare.affiliate_id');
        $param = (string) config('compare.affiliate_param');
        if ($id === '' || preg_match('/^[A-Za-z0-9_-]{1,40}$/', $param) !== 1) {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').rawurlencode($param).'='.rawurlencode($id);
    }

    private function convert(int $minor, string $from, string $to): int
    {
        $rates = config('compare.rates');
        if (! is_array($rates) || ! isset($rates[$from], $rates[$to]) || $from === $to) {
            return $minor;
        }

        $usd = (int) round($minor * 1_000_000 / (int) $rates[$from]);

        return (int) round($usd * (int) $rates[$to] / 1_000_000);
    }
}

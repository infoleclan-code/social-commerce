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
 * offers from different stores. The rank is the landed total of the parts the
 * store published (item, shipping, tax). Expired cache is never returned.
 */
final class CompareService
{
    public function __construct(
        private readonly OfferProvider $provider,
        private readonly FxRates $fx,
        private readonly StoreDirectory $stores,
        private readonly CouponCatalog $coupons,
        private readonly PriceHistory $history,
        private readonly SavingsTracker $savings,
    ) {}

    /**
     * @param  array{brand?: string|null, size?: string|null, min_minor?: int|null, max_minor?: int|null, in_stock: bool, sort: string, currency: string, country?: string|null}  $filters
     * @return array<string, mixed>
     */
    public function search(string $query, array $filters): array
    {
        $country = Region::country($filters['country'] ?? null) ?? 'US';
        $filters['country'] = $country;
        $result = $this->present($query, $this->fresh($query, $country), $filters);
        $this->history->rememberSearch($query, $country, $filters['currency']);

        return $result;
    }

    public function cacheKey(string $query, string $country = 'US'): string
    {
        $country = Region::country($country) ?? 'US';

        return 'compare:'.hash('sha256', mb_strtolower(trim($query)).'|'.$country.'|'.(string) config('compare.provider'));
    }

    /** @return array{url: string} */
    public function click(User $user, string $offerId): array
    {
        $offer = Cache::get($this->offerCacheKey($offerId));
        if (! is_array($offer) || ! is_string($offer['url'] ?? null)) {
            throw ApiException::offerExpired();
        }

        $merchant = StoreUrl::merchant((string) $offer['url']);
        if ($merchant === null) {
            throw ApiException::offerExpired();
        }
        $url = $this->affiliate($merchant);
        $recordedHost = strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = $this->stores->host($url);
        if ($recordedHost === '' || $host === '' || $this->stores->blocked($host)) {
            throw ApiException::offerExpired();
        }

        DB::table('outbound_clicks')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $user->id,
            'store' => mb_substr((string) ($offer['store'] ?? 'Store'), 0, 120),
            'host' => mb_substr($recordedHost, 0, 190),
            'offer_key' => $offerId,
            'created_at' => now(),
        ]);
        $this->savings->record($user, $offer);

        return ['url' => $url];
    }

    /** @return array{checked_at: int, offers: list<array<string, mixed>>} */
    private function fresh(string $query, string $country): array
    {
        $ttl = max(30, (int) config('compare.cache_ttl', 600));
        $cached = Cache::get($this->cacheKey($query, $country));
        if (
            is_array($cached)
            && isset($cached['checked_at'], $cached['offers'])
            && is_array($cached['offers'])
            && (time() - (int) $cached['checked_at']) < $ttl
        ) {
            return ['checked_at' => (int) $cached['checked_at'], 'offers' => array_values($cached['offers'])];
        }

        try {
            $offers = $this->provider->search($query, [
                'country' => $country,
                'gl' => Region::gl($country),
                'hl' => Region::hl($country),
            ]);
        } catch (Throwable $exception) {
            report(new RuntimeException('Offer provider failed: '.$exception::class));
            throw ApiException::compareUnavailable();
        }

        $payload = ['checked_at' => time(), 'offers' => array_values($offers)];
        Cache::put($this->cacheKey($query, $country), $payload, $ttl);

        return $payload;
    }

    /**
     * @param  array{checked_at: int, offers: list<array<string, mixed>>}  $payload
     * @param  array{brand?: string|null, size?: string|null, min_minor?: int|null, max_minor?: int|null, in_stock: bool, sort: string, currency: string, country?: string|null}  $filters
     * @return array<string, mixed>
     */
    private function present(string $query, array $payload, array $filters): array
    {
        $checkedAt = gmdate('c', $payload['checked_at']);
        $currency = $filters['currency'];
        $country = Region::country($filters['country'] ?? null) ?? 'US';
        $groups = [];

        foreach ($payload['offers'] as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $offer = $this->normalize($raw, $currency, $country, $checkedAt);
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
            foreach ($current as $offer) {
                $this->history->record($query, $offer);
            }
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
        foreach ($products as $index => $product) {
            $highest = 0;
            foreach ($product['offers'] as $priced) {
                $highest = max($highest, (int) $priced['total_minor']);
            }
            foreach ($product['offers'] as $offerIndex => $offer) {
                // The id is the snapshot the shopper is looking at. A later search
                // that hides the dearer stores must not reuse it.
                $offer['id'] = substr(hash('sha256', $offer['id'].'|'.$offer['currency'].'|'.$highest), 0, 24);
                $offer['highest_minor'] = $highest;
                $this->publish($offer);
                unset($offer['rank'], $offer['url']);
                $products[$index]['offers'][$offerIndex] = $offer;
            }
            if (isset($product['unavailable'])) {
                foreach ($product['unavailable'] as $offerIndex => $offer) {
                    unset($offer['rank'], $offer['url'], $offer['id']);
                    $products[$index]['unavailable'][$offerIndex] = $offer;
                }
            }
        }

        return [
            'query' => $query,
            'currency' => $currency,
            'minor_unit' => $this->fx->exponent($currency),
            'country' => $country,
            'checked_at' => $checkedAt,
            'products' => $products,
        ];
    }

    /** @param  array<string, mixed>  $raw */
    private function normalize(array $raw, string $currency, string $country, string $checkedAt): ?array
    {
        $url = StoreUrl::merchant((string) ($raw['url'] ?? ''));
        $store = trim((string) ($raw['store'] ?? ''));
        if ($store === '' || $url === null || ! is_numeric($raw['price_minor'] ?? null)) {
            return null;
        }
        $host = $this->stores->host($url);
        if ($host === '' || $this->stores->blocked($host)) {
            return null;
        }

        $from = strtoupper((string) ($raw['currency'] ?? 'USD'));
        $price = $this->fx->convert((int) $raw['price_minor'], $from, $currency);
        if ($price === null) {
            return null;
        }
        $shippingKnown = (bool) ($raw['shipping_known'] ?? false);
        $shipping = null;
        if ($shippingKnown && is_numeric($raw['shipping_minor'] ?? null)) {
            $shipping = $this->fx->convert((int) $raw['shipping_minor'], $from, $currency);
            if ($shipping === null) {
                return null;
            }
        }
        $taxKnown = (bool) ($raw['tax_known'] ?? false);
        $taxIncluded = (bool) ($raw['tax_included'] ?? false);
        $tax = null;
        if ($taxKnown && is_numeric($raw['tax_minor'] ?? null)) {
            $tax = $this->fx->convert((int) $raw['tax_minor'], $from, $currency);
            if ($tax === null) {
                return null;
            }
        }
        $coupons = $this->pricedCoupons($this->coupons->forHost($host, $country), $price);
        $total = $price + ($shippingKnown && $shipping !== null ? $shipping : 0);
        if ($taxKnown && $tax !== null && ! $taxIncluded) {
            $total += $tax;
        }
        $id = substr(hash('sha256', implode('|', [
            strtolower($store),
            $url,
            (string) $raw['price_minor'],
            (string) ($raw['shipping_minor'] ?? ''),
            (string) ($raw['tax_minor'] ?? ''),
            (string) ($raw['availability'] ?? ''),
        ])), 0, 24);
        $ships = $this->shipsTo($raw['ships_to'] ?? null, $country);
        $trust = $this->stores->describe($host, [
            'rating' => is_numeric($raw['rating'] ?? null) ? (float) $raw['rating'] : null,
            'review_count' => is_numeric($raw['review_count'] ?? null) ? (int) $raw['review_count'] : null,
        ]);

        return [
            'id' => $id,
            'store' => mb_substr($store, 0, 120),
            'title' => mb_substr((string) ($raw['title'] ?? $store), 0, 180),
            'brand' => mb_substr((string) ($raw['brand'] ?? $store), 0, 80),
            'model' => mb_substr((string) ($raw['model'] ?? $raw['title'] ?? $store), 0, 120),
            'size' => isset($raw['size']) && $raw['size'] !== null && $raw['size'] !== '' ? mb_substr((string) $raw['size'], 0, 40) : null,
            'price_minor' => $price,
            'shipping_minor' => $shipping,
            'shipping_known' => $shippingKnown,
            'tax_minor' => $tax,
            'tax_known' => $taxKnown,
            'tax_included' => $taxIncluded,
            'total_minor' => $total,
            'total_known' => $shippingKnown && $taxKnown,
            'currency' => $currency,
            'availability' => ($raw['availability'] ?? '') === 'out_of_stock' ? 'out_of_stock' : 'in_stock',
            'checked_at' => $checkedAt,
            'url' => $url,
            'host' => $host,
            'rating' => $trust['rating'],
            'review_count' => $trust['review_count'],
            'trust' => $trust['trust'],
            'ships_to_user' => $ships,
            'coupons' => $coupons,
            'rank' => $total,
        ];
    }

    /**
     * A percent in the coupon label reduces the item price. Anything else is shown and is not part of the total.
     *
     * @param  list<array{code: string, label: string}>  $coupons
     * @return list<array{code: string, label: string, included_in_total: bool}>
     */
    private function pricedCoupons(array $coupons, int &$price): array
    {
        $best = 0;
        $winner = null;
        foreach ($coupons as $index => $coupon) {
            if (preg_match('/(\d{1,2})\s*%/', $coupon['label'], $match) !== 1) {
                continue;
            }
            $percent = (int) $match[1];
            if ($percent < 1 || $percent > 90) {
                continue;
            }
            $discount = (int) round($price * $percent / 100);
            if ($discount > $best) {
                $best = $discount;
                $winner = $index;
            }
        }
        if ($best > 0) {
            $price = max(0, $price - $best);
        }
        $priced = [];
        foreach ($coupons as $index => $coupon) {
            $priced[] = $coupon + ['included_in_total' => $winner === $index];
        }

        return $priced;
    }

    private function shipsTo(mixed $ships, string $country): ?bool
    {
        if (! is_array($ships)) {
            return null;
        }
        $codes = array_map(fn (mixed $code): string => strtoupper((string) $code), $ships);

        return in_array($country, $codes, true);
    }

    /** @param  array<string, mixed>  $offer */
    private function passes(array $offer, array $filters): bool
    {
        if ($filters['in_stock'] === true && $offer['availability'] !== 'in_stock') {
            return false;
        }
        if (($offer['ships_to_user'] ?? null) === false) {
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
        $amount = $offer['rank'];
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
            if ($sort !== 'price_asc') {
                $group = $this->rankGroup($left) <=> $this->rankGroup($right);
                if ($group !== 0) {
                    return $sort === 'total_desc' ? -$group : $group;
                }
            }
            $compared = match ($sort) {
                'total_desc' => $right['rank'] <=> $left['rank'],
                'price_asc' => $left['price_minor'] <=> $right['price_minor'],
                default => $left['rank'] <=> $right['rank'],
            };

            return $compared !== 0 ? $compared : strcmp($left['store'], $right['store']);
        });

        return $offers;
    }

    /** Known shipping and tax sort ahead of a total that treats a missing part as zero. */
    private function rankGroup(array $offer): int
    {
        return ($offer['shipping_known'] ? 0 : 2) + ($offer['tax_known'] ? 0 : 1);
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
            'price_minor' => (int) $offer['total_minor'],
            'highest_minor' => (int) ($offer['highest_minor'] ?? $offer['total_minor']),
            'currency' => (string) $offer['currency'],
            'offer_key' => (string) $offer['id'],
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
}

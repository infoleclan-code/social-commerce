<?php

declare(strict_types=1);

namespace App\Services\Compare;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Google Shopping via SerpAPI. A shopping row is a product candidate: it has a
 * product id and an immersive-product token, not a merchant link. Sellers, the
 * store URL, brand, and size come from the Google Immersive Product API.
 *
 * https://serpapi.com/google-shopping-api
 * https://serpapi.com/google-immersive-product-api
 */
final class SerpApiShoppingProvider implements OfferProvider
{
    private const MAX_CANDIDATES = 4;

    /** Currency of the requested storefront, used when a row omits its own. */
    private string $currencyHint = 'USD';

    public function __construct(private readonly string $apiKey) {}

    public function search(string $query, array $context = []): array
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('SerpAPI key is not configured');
        }
        $this->currencyHint = $this->currencyForGl((string) ($context['gl'] ?? 'us'));

        $shopping = $this->get([
            'engine' => 'google_shopping',
            'q' => $query,
            'api_key' => $this->apiKey,
            'gl' => (string) ($context['gl'] ?? 'us'),
            'hl' => (string) ($context['hl'] ?? 'en'),
            'num' => 16,
        ]);
        $rows = $shopping['shopping_results'] ?? null;
        if (! is_array($rows)) {
            throw new RuntimeException('SerpAPI shopping response had no shopping_results');
        }
        if ($rows === []) {
            return [];
        }

        $tokens = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $token = $this->pageToken($row);
            if ($token === null || isset($tokens[$token])) {
                continue;
            }
            $tokens[$token] = true;
            if (count($tokens) >= self::MAX_CANDIDATES) {
                break;
            }
        }
        if ($tokens === []) {
            throw new RuntimeException('SerpAPI shopping response had no immersive product token');
        }

        $offers = $this->sellers(array_keys($tokens));
        if ($offers === []) {
            throw new RuntimeException('SerpAPI returned products without merchant offers');
        }

        usort($offers, function (array $left, array $right): int {
            $group = $this->knownGroup($left) <=> $this->knownGroup($right);
            if ($group !== 0) {
                return $group;
            }

            return $this->partial($left) <=> $this->partial($right);
        });

        return $offers;
    }

    /**
     * @param  list<string>  $tokens
     * @return list<array<string, mixed>>
     */
    private function sellers(array $tokens): array
    {
        $timeout = $this->timeout();
        $responses = Http::pool(function (Pool $pool) use ($tokens, $timeout): void {
            foreach ($tokens as $index => $token) {
                $pool->as((string) $index)
                    ->timeout($timeout)
                    ->connectTimeout(3)
                    ->acceptJson()
                    ->get('https://serpapi.com/search.json', [
                        'engine' => 'google_immersive_product',
                        'page_token' => $token,
                        'more_stores' => 'true',
                        'api_key' => $this->apiKey,
                    ]);
            }
        });

        $offers = [];
        foreach ($responses as $response) {
            if (! $response instanceof Response || ! $response->successful()) {
                continue;
            }
            $product = $response->json('product_results');
            if (! is_array($product)) {
                continue;
            }
            foreach ($this->mapStores($product) as $offer) {
                $offers[] = $offer;
            }
        }

        return $offers;
    }

    /** @param  array<string, mixed>  $query */
    private function get(array $query): array
    {
        $response = Http::timeout($this->timeout())
            ->connectTimeout(3)
            ->acceptJson()
            ->get('https://serpapi.com/search.json', $query);
        if (! $response->successful()) {
            throw new RuntimeException('SerpAPI request failed');
        }
        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException('SerpAPI response was not JSON');
        }

        return $json;
    }

    private function timeout(): int
    {
        return max(8, (int) config('compare.timeout_seconds', 12));
    }

    /** @param  array<string, mixed>  $row */
    private function pageToken(array $row): ?string
    {
        $token = $row['immersive_product_page_token'] ?? null;
        if (is_string($token) && $token !== '') {
            return $token;
        }
        $url = $row['serpapi_immersive_product_api'] ?? null;
        if (! is_string($url) || $url === '') {
            return null;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host !== 'serpapi.com') {
            return null;
        }
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $fromUrl = $query['page_token'] ?? null;

        return is_string($fromUrl) && $fromUrl !== '' ? $fromUrl : null;
    }

    /**
     * @param  array<string, mixed>  $product
     * @return list<array<string, mixed>>
     */
    private function mapStores(array $product): array
    {
        $stores = $product['stores'] ?? null;
        if (! is_array($stores)) {
            return [];
        }
        $brand = trim((string) ($product['brand'] ?? ''));
        $model = trim((string) ($product['title'] ?? ''));
        $size = $this->size($product);
        $offers = [];
        foreach ($stores as $store) {
            if (! is_array($store)) {
                continue;
            }
            $mapped = $this->mapStore($store, $brand, $model, $size);
            if ($mapped !== null) {
                $offers[] = $mapped;
            }
        }

        return $offers;
    }

    /**
     * @param  array<string, mixed>  $store
     * @return array<string, mixed>|null
     */
    private function mapStore(array $store, string $brand, string $model, ?string $size): ?array
    {
        $name = trim((string) ($store['name'] ?? ''));
        $url = StoreUrl::merchant((string) ($store['link'] ?? ''));
        $price = $store['extracted_price'] ?? null;
        if ($name === '' || $url === null || ! is_numeric($price)) {
            return null;
        }
        $title = trim((string) ($store['title'] ?? $model));
        $currency = $this->currencyOf($store);
        $shipping = $this->shipping($store, $currency);
        $tax = $this->tax($store, $currency);
        $details = strtolower(implode(' ', array_map(
            fn (mixed $line): string => is_string($line) ? $line : '',
            is_array($store['details_and_offers'] ?? null) ? $store['details_and_offers'] : [],
        )));

        return [
            'store' => mb_substr($name, 0, 120),
            'title' => mb_substr($title !== '' ? $title : $name, 0, 180),
            'url' => $url,
            'brand' => mb_substr($brand !== '' ? $brand : $name, 0, 80),
            'model' => mb_substr($model !== '' ? $model : $title, 0, 120),
            'size' => $size,
            'price_minor' => $this->minor((float) $price, $currency),
            'shipping_minor' => $shipping['minor'],
            'shipping_known' => $shipping['known'],
            'tax_minor' => $tax['minor'],
            'tax_known' => $tax['known'],
            'tax_included' => $tax['included'],
            'currency' => $currency,
            'availability' => str_contains($details, 'out of stock') ? 'out_of_stock' : 'in_stock',
            'rating' => is_numeric($store['rating'] ?? null) ? (float) $store['rating'] : null,
            'review_count' => is_numeric($store['reviews'] ?? null) ? (int) $store['reviews'] : null,
            'ships_to' => null,
        ];
    }

    /** @param  array<string, mixed>  $product */
    private function size(array $product): ?string
    {
        $variants = $product['variants'] ?? null;
        if (! is_array($variants)) {
            return null;
        }
        foreach ($variants as $group) {
            if (! is_array($group) || preg_match('/size/i', (string) ($group['title'] ?? '')) !== 1) {
                continue;
            }
            $items = $group['items'] ?? null;
            if (! is_array($items)) {
                continue;
            }
            foreach ($items as $item) {
                if (is_array($item) && ($item['selected'] ?? false) === true && is_string($item['name'] ?? null) && $item['name'] !== '') {
                    return mb_substr($item['name'], 0, 40);
                }
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $store
     * @return array{known: bool, minor: int|null}
     */
    private function shipping(array $store, string $currency): array
    {
        $text = trim((string) ($store['shipping'] ?? ''));
        if ($text === '' && is_array($store['details_and_offers'] ?? null)) {
            foreach ($store['details_and_offers'] as $line) {
                if (is_string($line) && preg_match('/delivery|shipping/i', $line) === 1) {
                    $text = trim($line);
                    break;
                }
            }
        }
        if (preg_match('/free\s+(?:delivery|shipping)\s+(?:on|over|with|for)\b/i', $text) === 1) {
            return ['known' => false, 'minor' => null];
        }
        if (is_numeric($store['shipping_extracted'] ?? null)) {
            return ['known' => true, 'minor' => $this->minor((float) $store['shipping_extracted'], $currency)];
        }
        if (preg_match('/(?:\$|USD\s*|€|£|¥|￥|₩|₹)\s*(\d+(?:\.\d{1,2})?)/iu', $text, $match) === 1) {
            return ['known' => true, 'minor' => $this->minor((float) $match[1], $currency)];
        }
        if (preg_match('/^free(?:\s+(?:delivery|shipping))?$/i', $text) === 1) {
            return ['known' => true, 'minor' => 0];
        }

        return ['known' => false, 'minor' => null];
    }

    /**
     * @param  array<string, mixed>  $store
     * @return array{known: bool, included: bool, minor: int|null}
     */
    private function tax(array $store, string $currency): array
    {
        $text = strtolower(trim((string) ($store['tax'] ?? '')));
        if ($text === '' && is_array($store['details_and_offers'] ?? null)) {
            foreach ($store['details_and_offers'] as $line) {
                if (is_string($line) && preg_match('/\btax\b|\bvat\b/i', $line) === 1) {
                    $text = strtolower(trim($line));
                    break;
                }
            }
        }
        if ($text === '') {
            return ['known' => false, 'included' => false, 'minor' => null];
        }
        if (preg_match('/incl(?:uded|\.)?|includes|incl\b/i', $text) === 1) {
            return ['known' => true, 'included' => true, 'minor' => 0];
        }
        if (preg_match('/(?:\$|USD\s*|€|£|¥|￥|₩|₹)\s*(\d+(?:\.\d{1,2})?)/iu', $text, $match) === 1) {
            return ['known' => true, 'included' => false, 'minor' => $this->minor((float) $match[1], $currency)];
        }

        return ['known' => false, 'included' => false, 'minor' => null];
    }

    /** A published shipping and tax total sorts ahead of a total that treats a missing part as zero. */
    private function knownGroup(array $offer): int
    {
        return ($offer['shipping_known'] ? 0 : 2) + ($offer['tax_known'] ? 0 : 1);
    }

    /** @param  array<string, mixed>  $offer */
    private function partial(array $offer): int
    {
        $total = (int) $offer['price_minor'];
        if ($offer['shipping_known']) {
            $total += (int) $offer['shipping_minor'];
        }
        if ($offer['tax_known'] && ! $offer['tax_included']) {
            $total += (int) $offer['tax_minor'];
        }

        return $total;
    }

    private function currencyForGl(string $gl): string
    {
        $gl = strtolower($gl);
        foreach (Region::COUNTRIES as $country) {
            if ($country['gl'] === $gl) {
                return $country['currency'];
            }
        }

        return 'USD';
    }

    /** @param  array<string, mixed>  $store */
    private function currencyOf(array $store): string
    {
        $explicit = strtoupper(trim((string) ($store['currency'] ?? '')));
        if (preg_match('/^[A-Z]{3}$/', $explicit) === 1) {
            return $explicit;
        }
        $price = (string) ($store['price'] ?? '');
        if (str_contains($price, '€')) {
            return 'EUR';
        }
        if (str_contains($price, '£')) {
            return 'GBP';
        }
        if (str_contains($price, '₩')) {
            return 'KRW';
        }
        if (str_contains($price, '₹')) {
            return 'INR';
        }
        if (str_contains($price, '¥') || str_contains($price, '￥')) {
            return $this->currencyHint === 'CNY' ? 'CNY' : 'JPY';
        }
        if (str_contains($price, '$')) {
            return in_array($this->currencyHint, ['USD', 'CAD', 'AUD', 'NZD', 'SGD', 'MXN'], true)
                ? $this->currencyHint
                : 'USD';
        }

        return $this->currencyHint;
    }

    private function minor(float $major, string $currency): int
    {
        $exponent = app(FxRates::class)->exponent($currency);

        return (int) round($major * (10 ** $exponent));
    }
}

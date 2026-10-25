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

    public function __construct(private readonly string $apiKey) {}

    public function search(string $query): array
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('SerpAPI key is not configured');
        }

        $shopping = $this->get([
            'engine' => 'google_shopping',
            'q' => $query,
            'api_key' => $this->apiKey,
            'gl' => 'us',
            'hl' => 'en',
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
            $leftRank = $left['shipping_known'] ? $left['price_minor'] + (int) $left['shipping_minor'] : $left['price_minor'];
            $rightRank = $right['shipping_known'] ? $right['price_minor'] + (int) $right['shipping_minor'] : $right['price_minor'];

            return $leftRank <=> $rightRank;
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
        $shipping = $this->shipping($store);
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
            'price_minor' => (int) round(((float) $price) * 100),
            'shipping_minor' => $shipping['minor'],
            'shipping_known' => $shipping['known'],
            'currency' => 'USD',
            'availability' => str_contains($details, 'out of stock') ? 'out_of_stock' : 'in_stock',
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
    private function shipping(array $store): array
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
            return ['known' => true, 'minor' => (int) round(((float) $store['shipping_extracted']) * 100)];
        }
        if (preg_match('/(?:\$|USD\s*|€|£)\s*(\d+(?:\.\d{1,2})?)/i', $text, $match) === 1) {
            return ['known' => true, 'minor' => (int) round(((float) $match[1]) * 100)];
        }
        if (preg_match('/^free(?:\s+(?:delivery|shipping))?$/i', $text) === 1) {
            return ['known' => true, 'minor' => 0];
        }

        return ['known' => false, 'minor' => null];
    }
}

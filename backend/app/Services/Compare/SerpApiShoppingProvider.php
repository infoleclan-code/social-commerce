<?php

declare(strict_types=1);

namespace App\Services\Compare;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Google Shopping results from SerpAPI. https://serpapi.com/google-shopping-api
 * The engine parameter is google_shopping. Shipping is parsed from the delivery
 * line when a price or the word "free" is present, and left unknown otherwise.
 */
final class SerpApiShoppingProvider implements OfferProvider
{
    public function __construct(private readonly string $apiKey) {}

    public function search(string $query): array
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('SerpAPI key is not configured');
        }

        $response = Http::timeout((int) config('compare.timeout_seconds', 4))
            ->connectTimeout(2)
            ->acceptJson()
            ->get('https://serpapi.com/search.json', [
                'engine' => 'google_shopping',
                'q' => $query,
                'api_key' => $this->apiKey,
                'gl' => 'us',
                'hl' => 'en',
                'num' => 24,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('SerpAPI request failed');
        }

        $rows = $response->json('shopping_results');
        if (! is_array($rows)) {
            return [];
        }

        $offers = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $mapped = $this->map($row);
            if ($mapped !== null) {
                $offers[] = $mapped;
            }
        }

        return $offers;
    }

    /** @param  array<string, mixed>  $row */
    private function map(array $row): ?array
    {
        $title = trim((string) ($row['title'] ?? ''));
        $store = trim((string) ($row['source'] ?? ''));
        $url = (string) ($row['link'] ?? '');
        $price = $row['extracted_price'] ?? null;
        if ($title === '' || $store === '' || ! is_string($url) || ! str_starts_with($url, 'https://') || ! is_numeric($price)) {
            return null;
        }

        $delivery = (string) ($row['delivery'] ?? '');
        $snippet = strtolower($title.' '.$delivery.' '.(string) ($row['snippet'] ?? ''));
        $shipping = $this->shipping($delivery);
        [$brand, $model, $size] = $this->identity($title);

        return [
            'store' => mb_substr($store, 0, 120),
            'title' => mb_substr($title, 0, 180),
            'url' => $url,
            'brand' => $brand,
            'model' => $model,
            'size' => $size,
            'price_minor' => (int) round(((float) $price) * 100),
            'shipping_minor' => $shipping['minor'],
            'shipping_known' => $shipping['known'],
            'currency' => 'USD',
            'availability' => str_contains($snippet, 'out of stock') ? 'out_of_stock' : 'in_stock',
        ];
    }

    /** @return array{known: bool, minor: int|null} */
    private function shipping(string $delivery): array
    {
        if ($delivery === '') {
            return ['known' => false, 'minor' => null];
        }
        if (preg_match('/free/i', $delivery) === 1) {
            return ['known' => true, 'minor' => 0];
        }
        if (preg_match('/(\d+(?:\.\d{1,2})?)/', $delivery, $match) === 1) {
            return ['known' => true, 'minor' => (int) round(((float) $match[1]) * 100)];
        }

        return ['known' => false, 'minor' => null];
    }

    /** @return array{0: string, 1: string, 2: string|null} */
    private function identity(string $title): array
    {
        $size = null;
        if (preg_match('/\b(?:size\s*)?(\d{1,2}(?:\.\d)?|XXS|XS|S|M|L|XL|XXL)\b/i', $title, $match) === 1) {
            $size = strtoupper($match[1]);
        }
        $words = preg_split('/\s+/', $title) ?: [];
        $brand = $words[0] ?? $title;
        $model = trim(preg_replace('/\b(?:size\s*)?(?:\d{1,2}(?:\.\d)?|XXS|XS|S|M|L|XL|XXL)\b/i', '', $title) ?? $title);

        return [mb_substr($brand, 0, 80), mb_substr($model !== '' ? $model : $title, 0, 120), $size];
    }
}

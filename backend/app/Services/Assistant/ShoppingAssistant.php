<?php

declare(strict_types=1);

namespace App\Services\Assistant;

use App\Exceptions\ApiException;
use App\Services\Compare\CompareService;
use RuntimeException;
use Throwable;

/** Turns a plain-language request into a short note and the existing top-3 compare flow. */
final class ShoppingAssistant
{
    public function __construct(
        private readonly LlmClient $llm,
        private readonly CompareService $compare,
    ) {}

    /**
     * @param  array{brand?: string|null, size?: string|null, min_minor?: int|null, max_minor?: int|null, in_stock: bool, sort: string, currency: string, country?: string|null}  $filters
     * @return array<string, mixed>
     */
    public function ask(string $message, array $filters): array
    {
        try {
            $raw = $this->llm->complete(
                'You help a shopper compare prices. Reply with JSON only: {"reply": string, "products": [{"query": string, "brand": string|null, "summary": string, "pros": string[], "cons": string[], "max_minor": int|null}]}. At most 3 products. query is a short product name a store search can use. Do not invent prices.',
                $message,
            );
            $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            report(new RuntimeException('Assistant failed: '.$exception::class));
            throw ApiException::assistantUnavailable();
        }
        if (! is_array($decoded)) {
            throw ApiException::assistantUnavailable();
        }
        $products = [];
        foreach (array_slice(is_array($decoded['products'] ?? null) ? $decoded['products'] : [], 0, 3) as $product) {
            if (! is_array($product)) {
                continue;
            }
            $query = trim((string) ($product['query'] ?? ''));
            if (strlen($query) < 2 || strlen($query) > 120) {
                continue;
            }
            $next = $filters;
            if (isset($product['max_minor']) && is_numeric($product['max_minor'])) {
                $next['max_minor'] = (int) $product['max_minor'];
            }
            if (is_string($product['brand'] ?? null) && trim($product['brand']) !== '') {
                $next['brand'] = trim($product['brand']);
            }
            try {
                $compared = $this->compare->search($query, $next);
            } catch (Throwable) {
                continue;
            }
            $products[] = [
                'query' => $query,
                'summary' => mb_substr((string) ($product['summary'] ?? ''), 0, 240),
                'pros' => $this->lines($product['pros'] ?? []),
                'cons' => $this->lines($product['cons'] ?? []),
                'compare' => $compared,
            ];
        }
        if ($products === []) {
            throw ApiException::assistantUnavailable();
        }

        return [
            'reply' => mb_substr((string) ($decoded['reply'] ?? 'Here are some options to compare.'), 0, 400),
            'products' => $products,
        ];
    }

    /** @return list<string> */
    private function lines(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $lines = [];
        foreach (array_slice($value, 0, 4) as $line) {
            $text = trim((string) $line);
            if ($text !== '') {
                $lines[] = mb_substr($text, 0, 120);
            }
        }

        return $lines;
    }
}

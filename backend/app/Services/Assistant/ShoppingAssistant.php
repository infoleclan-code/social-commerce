<?php

declare(strict_types=1);

namespace App\Services\Assistant;

use App\Exceptions\ApiException;
use App\Services\Compare\CompareService;
use App\Services\Compare\FxRates;
use RuntimeException;
use Throwable;

/** Turns a plain-language request into a short note and the existing top-3 compare flow. */
final class ShoppingAssistant
{
    public function __construct(
        private readonly LlmClient $llm,
        private readonly CompareService $compare,
        private readonly FxRates $fx,
    ) {}

    /**
     * @param  array{brand?: string|null, size?: string|null, min_minor?: int|null, max_minor?: int|null, in_stock: bool, sort: string, currency: string, country?: string|null}  $filters
     * @return array<string, mixed>
     */
    public function ask(string $message, array $filters): array
    {
        $currency = strtoupper((string) ($filters['currency'] ?? 'USD'));
        $exponent = $this->fx->exponent($currency);
        $unit = $exponent === 0
            ? 'This currency has no minor unit, so max_minor is the major amount.'
            : 'max_minor is an integer in minor units (10^'.$exponent.' per major unit of '.$currency.').';
        try {
            $raw = $this->llm->complete(
                'You help a shopper compare prices in '.$currency.'. '.$unit.' Reply with JSON only: {"reply": string, "products": [{"query": string, "brand": string|null, "summary": string, "pros": string[], "cons": string[], "max_minor": int|null}]}. At most 3 products. max_minor is an integer from 0 to 100000000, or null. query is a short product name a store search can use. Do not invent prices.',
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
            $budget = $this->budget($message, $product['max_minor'] ?? null, $exponent);
            if ($budget !== null) {
                $next['max_minor'] = $budget;
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

    private function budget(string $message, mixed $value, int $exponent): ?int
    {
        if (! is_numeric($value) || floor((float) $value) != (float) $value) {
            return null;
        }
        $max = (int) $value;
        if ($max < 0 || $max > 100_000_000) {
            return null;
        }
        if ($exponent === 0) {
            return $max;
        }
        if (preg_match('/\$\s*(\d+(?:\.\d{1,2})?)|\b(\d+(?:\.\d{1,2})?)\s+dollars\b/i', $message, $match) !== 1) {
            return $max;
        }
        $major = (float) (($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? '0'));
        $scaled = (int) round($major * (10 ** $exponent));
        $asMajor = (int) round($major);
        if ($max === $asMajor && $scaled !== $asMajor && $scaled <= 100_000_000) {
            return $scaled;
        }

        return $max;
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

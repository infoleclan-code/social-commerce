<?php

declare(strict_types=1);

namespace App\Services\Compare;

use Throwable;

/** Other-brand products in the same kind of search, kept only when they cost less. */
final class Alternatives
{
    public function __construct(private readonly CompareService $compare) {}

    /**
     * @param  array{brand?: string|null, size?: string|null, min_minor?: int|null, max_minor?: int|null, in_stock: bool, sort: string, currency: string, country?: string|null}  $filters
     * @return array<string, mixed>
     */
    public function suggest(string $query, array $filters): array
    {
        $original = $this->compare->search($query, $filters);
        $best = $original['products'][0]['offers'][0]['total_minor'] ?? null;
        $brand = strtolower((string) ($original['products'][0]['brand'] ?? ''));
        $alternativeQuery = match (true) {
            str_contains($brand, 'nike') => 'adidas samba',
            str_contains($brand, 'adidas') => 'nike sneakers',
            default => $this->categoryQuery($query, $original),
        };
        $options = [];
        if (is_int($best) && $alternativeQuery !== null) {
            $next = $filters;
            $next['brand'] = null;
            $next['size'] = null;
            try {
                $compared = $this->compare->search($alternativeQuery, $next);
            } catch (Throwable) {
                $compared = null;
            }
            $alt = is_array($compared) ? ($compared['products'][0]['offers'][0]['total_minor'] ?? null) : null;
            if (is_int($alt) && $alt < $best && $compared !== null) {
                $saved = $best - $alt;
                $options[] = [
                    'query' => $alternativeQuery,
                    'title' => $compared['products'][0]['title'] ?? $alternativeQuery,
                    'brand' => $compared['products'][0]['brand'] ?? '',
                    'best_total_minor' => $alt,
                    'savings_minor' => $saved,
                    'savings_percent' => (int) round($saved / $best * 100),
                    'quality_note' => 'Similar kind of product from another brand. Not a lab match.',
                    'compare' => $compared,
                ];
            }
        }

        return [
            'query' => $query,
            'best_total_minor' => is_int($best) ? $best : null,
            'currency' => $filters['currency'],
            'alternatives' => $options,
        ];
    }

    /** @param  array<string, mixed>  $original */
    private function categoryQuery(string $query, array $original): ?string
    {
        $title = strtolower((string) ($original['products'][0]['title'] ?? ''));
        $text = strtolower($query).' '.$title;
        foreach (['headphones', 'sneakers', 'shoes', 'jacket', 'watch', 'glasses'] as $category) {
            if (str_contains($text, $category)) {
                return $category;
            }
        }

        return null;
    }
}

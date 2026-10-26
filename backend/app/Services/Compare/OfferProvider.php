<?php

declare(strict_types=1);

namespace App\Services\Compare;

/**
 * One store offer, before currency conversion and ranking.
 *
 * price_minor and shipping_minor are integer minor units of `currency`.
 * shipping_known false means the provider did not say, which is not the same as free.
 * availability is `in_stock` or `out_of_stock`.
 * tax_known false means the provider did not say. tax_included means the item price already contains it.
 * ships_to null means the provider did not say which countries it delivers to.
 *
 * @param  array{country?: string, gl?: string, hl?: string}  $context
 */
interface OfferProvider
{
    /**
     * @param  array{country?: string, gl?: string, hl?: string}  $context
     * @return list<array<string, mixed>>
     */
    public function search(string $query, array $context = []): array;
}

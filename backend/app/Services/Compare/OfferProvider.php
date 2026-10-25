<?php

declare(strict_types=1);

namespace App\Services\Compare;

/**
 * One store offer, before currency conversion and ranking.
 *
 * price_minor and shipping_minor are integer minor units of `currency`.
 * shipping_known false means the provider did not say, which is not the same as free.
 * availability is `in_stock` or `out_of_stock`.
 */
interface OfferProvider
{
    /**
     * @return list<array{
     *     store: string,
     *     title: string,
     *     url: string,
     *     brand: string,
     *     model: string,
     *     size: string|null,
     *     price_minor: int,
     *     shipping_minor: int|null,
     *     shipping_known: bool,
     *     currency: string,
     *     availability: string
     * }>
     */
    public function search(string $query): array;
}

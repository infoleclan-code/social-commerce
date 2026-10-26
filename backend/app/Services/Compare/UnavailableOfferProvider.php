<?php

declare(strict_types=1);

namespace App\Services\Compare;

use RuntimeException;

/** Used when a real provider is selected and its credentials are missing. */
final class UnavailableOfferProvider implements OfferProvider
{
    public function search(string $query, array $context = []): array
    {
        throw new RuntimeException('Offer provider is not configured');
    }
}

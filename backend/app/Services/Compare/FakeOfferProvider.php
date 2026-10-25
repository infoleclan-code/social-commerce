<?php

declare(strict_types=1);

namespace App\Services\Compare;

/**
 * Fixed catalog so search, ranking, and click tests do not depend on the network.
 * Prices are USD minor units. StockX and JD Sports are out of stock on purpose.
 */
final class FakeOfferProvider implements OfferProvider
{
    /** @var list<string> */
    private const STOPWORDS = ['sneakers', 'sneaker', 'shoes', 'shoe', 'trainers', 'the', 'a', 'for', 'men', 'women', 'kids'];

    public function search(string $query): array
    {
        $tokens = $this->tokens($query);
        if ($tokens === []) {
            return [];
        }

        return array_values(array_filter(self::catalog(), function (array $offer) use ($tokens): bool {
            $haystack = strtolower($offer['brand'].' '.$offer['model'].' '.$offer['title']);
            foreach ($tokens as $token) {
                if (! str_contains($haystack, $token)) {
                    return false;
                }
            }

            return true;
        }));
    }

    /** @return list<string> */
    private function tokens(string $query): array
    {
        $parts = preg_split('/[^a-z0-9]+/i', strtolower($query)) ?: [];

        return array_values(array_filter(
            $parts,
            fn (string $token): bool => strlen($token) >= 2 && ! in_array($token, self::STOPWORDS, true),
        ));
    }

    /** @return list<array<string, mixed>> */
    private static function catalog(): array
    {
        return [
            self::row('Finish Line', 'Nike Dunk Low', 'https://www.finishline.com/nike-dunk-low', 'Nike', 'Dunk Low', '10', 10_500, 0, true, 'in_stock'),
            self::row("Dick's", 'Nike Dunk Low', 'https://www.dickssportinggoods.com/nike-dunk-low', 'Nike', 'Dunk Low', '10', 9_900, 1_200, true, 'in_stock'),
            self::row('Foot Locker', 'Nike Dunk Low', 'https://www.footlocker.com/nike-dunk-low', 'Nike', 'Dunk Low', '10', 11_000, 800, true, 'in_stock'),
            self::row('Nike', 'Nike Dunk Low', 'https://www.nike.com/dunk-low', 'Nike', 'Dunk Low', '10', 12_000, 0, true, 'in_stock'),
            self::row('StockX', 'Nike Dunk Low', 'https://stockx.com/nike-dunk-low', 'Nike', 'Dunk Low', '10', 9_500, 0, true, 'out_of_stock'),
            self::row('adidas', 'Adidas Samba', 'https://www.adidas.com/samba', 'Adidas', 'Samba', '9', 10_000, 0, true, 'in_stock'),
            self::row('Foot Locker', 'Adidas Samba', 'https://www.footlocker.com/samba', 'Adidas', 'Samba', '9', 9_500, 1_000, true, 'in_stock'),
            self::row('JD Sports', 'Adidas Samba', 'https://www.jdsports.com/samba', 'Adidas', 'Samba', '9', 9_000, 0, true, 'out_of_stock'),
        ];
    }

    /** @return array<string, mixed> */
    private static function row(
        string $store,
        string $title,
        string $url,
        string $brand,
        string $model,
        ?string $size,
        int $price,
        ?int $shipping,
        bool $shippingKnown,
        string $availability,
    ): array {
        return [
            'store' => $store,
            'title' => $title,
            'url' => $url,
            'brand' => $brand,
            'model' => $model,
            'size' => $size,
            'price_minor' => $price,
            'shipping_minor' => $shippingKnown ? $shipping : null,
            'shipping_known' => $shippingKnown,
            'currency' => 'USD',
            'availability' => $availability,
        ];
    }
}

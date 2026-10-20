<?php

declare(strict_types=1);

namespace App\Services\Commerce;

use App\Support\Currency;

/** All-in price and a delivery date. The client never supplies these numbers. */
final class LandedCost
{
    /** @var list<string> */
    private const EU = [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT',
        'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
    ];

    /** @return array<string, mixed> */
    public static function quote(object $product, ?string $destination): array
    {
        $from = strtoupper((string) ($product->ships_from ?? 'US'));
        $to = $destination !== null && $destination !== '' ? strtoupper($destination) : $from;
        $merchandise = (int) $product->price_minor;
        $same = $from === $to;
        $shipping = (int) ($product->shipping_minor ?? 0) + ($same ? 0 : 800);
        $vat = in_array($to, self::EU, true) ? intdiv(($merchandise + $shipping) * (int) ($product->vat_bps ?? 0), 10000) : 0;
        $duty = $same ? 0 : intdiv($merchandise * (int) ($product->duty_bps ?? 0), 10000);
        $days = (int) ($product->handling_days ?? 2) + ($same ? 2 : 6);
        $currency = (string) $product->currency;

        return [
            'destination' => $to,
            'merchandise' => self::money($merchandise, $currency),
            'shipping' => self::money($shipping, $currency),
            'vat' => self::money($vat, $currency),
            'duty' => self::money($duty, $currency),
            'total' => self::money($merchandise + $shipping + $vat + $duty, $currency),
            'delivers_on' => now()->addDays($days)->toDateString(),
        ];
    }

    /** @return array{amount_minor: int, currency: string, minor_unit: int} */
    private static function money(int $minor, string $currency): array
    {
        return Currency::money($minor, $currency);
    }
}

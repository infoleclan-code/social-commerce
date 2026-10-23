<?php

declare(strict_types=1);

namespace App\Services\Commerce;

use App\Support\Currency;

/**
 * All-in price and a delivery date. The client never supplies these numbers.
 *
 * charge() is what checkout and 1-Tap collect: discounted merchandise times quantity, shipping once,
 * duty on that merchandise when the parcel crosses a border, and VAT on merchandise plus shipping
 * when the destination is in the EU. quote() is the same formula for one unit at the list price,
 * and it publishes the effective vat_bps and duty_bps (already zero when they do not apply) so the
 * client can recompute a discounted total with integer arithmetic and match the server.
 */
final class LandedCost
{
    /** @var list<string> */
    private const EU = [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT',
        'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
    ];

    /**
     * @return array{
     *     destination: string,
     *     merchandise_minor: int,
     *     shipping_minor: int,
     *     vat_minor: int,
     *     duty_minor: int,
     *     total_minor: int,
     *     currency: string,
     *     vat_bps: int,
     *     duty_bps: int,
     *     delivers_on: string
     * }
     */
    public static function charge(object $product, ?string $destination, int $unitMinor, int $quantity): array
    {
        $from = strtoupper((string) ($product->ships_from ?? 'US'));
        $to = $destination !== null && $destination !== '' ? strtoupper($destination) : $from;
        $quantity = max(1, $quantity);
        $merchandise = $unitMinor * $quantity;
        $same = $from === $to;
        $shipping = (int) ($product->shipping_minor ?? 0) + ($same ? 0 : 800);
        $vatBps = in_array($to, self::EU, true) ? (int) ($product->vat_bps ?? 0) : 0;
        $dutyBps = $same ? 0 : (int) ($product->duty_bps ?? 0);
        $vat = intdiv(($merchandise + $shipping) * $vatBps, 10000);
        $duty = intdiv($merchandise * $dutyBps, 10000);
        $days = (int) ($product->handling_days ?? 2) + ($same ? 2 : 6);

        return [
            'destination' => $to,
            'merchandise_minor' => $merchandise,
            'shipping_minor' => $shipping,
            'vat_minor' => $vat,
            'duty_minor' => $duty,
            'total_minor' => $merchandise + $shipping + $vat + $duty,
            'currency' => (string) $product->currency,
            'vat_bps' => $vatBps,
            'duty_bps' => $dutyBps,
            'delivers_on' => now()->addDays($days)->toDateString(),
        ];
    }

    /** @return array<string, mixed> */
    public static function quote(object $product, ?string $destination): array
    {
        $charge = self::charge($product, $destination, (int) $product->price_minor, 1);
        $currency = $charge['currency'];

        return [
            'destination' => $charge['destination'],
            'merchandise' => Currency::money($charge['merchandise_minor'], $currency),
            'shipping' => Currency::money($charge['shipping_minor'], $currency),
            'vat' => Currency::money($charge['vat_minor'], $currency),
            'duty' => Currency::money($charge['duty_minor'], $currency),
            'total' => Currency::money($charge['total_minor'], $currency),
            'vat_bps' => $charge['vat_bps'],
            'duty_bps' => $charge['duty_bps'],
            'delivers_on' => $charge['delivers_on'],
        ];
    }
}

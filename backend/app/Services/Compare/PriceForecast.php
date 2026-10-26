<?php

declare(strict_types=1);

namespace App\Services\Compare;

use Illuminate\Support\Carbon;

/** An estimate of whether to buy now. It is not a promise that a store will cut the price. */
final class PriceForecast
{
    public function __construct(
        private readonly PriceHistory $history,
        private readonly SaleCalendar $sales,
    ) {}

    /** @return array<string, mixed> */
    public function estimate(string $brand, string $model, ?string $size, string $currency, ?int $current): array
    {
        $chart = $this->history->chart($brand, $model, $size, $currency, $current);
        $event = $this->sales->next(now());
        $daysUntil = (int) abs(now()->startOfDay()->diffInDays(Carbon::parse($event['on'])->startOfDay(), false));
        $soon = $daysUntil <= 60;
        $nearLow = $chart['hint'] === 'good_now';
        $above = $chart['avg_90_minor'] !== null && $current !== null && $current > (int) round($chart['avg_90_minor'] * 1.03);
        $wait = $soon && $above && ! $nearLow;
        $percent = $wait ? (int) $event['drop_percent'] : 0;
        $drop = $wait && $current !== null ? (int) round($current * $percent / 100) : 0;

        return [
            'estimate' => true,
            'action' => $wait ? 'wait' : 'buy',
            'confidence' => count($chart['points']) >= 4 ? 'medium' : 'low',
            'expected_drop_minor' => $drop,
            'expected_drop_percent' => $percent,
            'currency' => $currency,
            'minor_unit' => $chart['minor_unit'],
            'next_event' => [
                'name' => $event['name'],
                'on' => $event['on'],
                'typical' => $event['typical'],
            ],
            'disclaimer' => 'This is an estimate from recent prices and a typical sale date. The store may not drop the price.',
        ];
    }
}

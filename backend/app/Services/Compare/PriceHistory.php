<?php

declare(strict_types=1);

namespace App\Services\Compare;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** One observed total per store per day, and a plain hint against the recent low and average. */
final class PriceHistory
{
    public function __construct(private readonly FxRates $fx) {}

    public static function productKey(string $brand, string $model, ?string $size): string
    {
        return hash('sha256', strtolower($brand).'|'.strtolower($model).'|'.strtolower((string) $size));
    }

    /** @param  array<string, mixed>  $offer */
    public function record(string $query, array $offer): void
    {
        $host = strtolower((string) parse_url((string) ($offer['url'] ?? ''), PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host) ?? $host;
        if ($host === '' || ! isset($offer['total_minor'], $offer['currency'])) {
            return;
        }
        $key = self::productKey((string) $offer['brand'], (string) $offer['model'], $offer['size'] ?? null);
        $match = [
            'product_key' => $key,
            'host' => mb_substr($host, 0, 190),
            'currency' => (string) $offer['currency'],
            'observed_on' => now()->toDateString(),
        ];
        $values = [
            'query' => mb_substr($query, 0, 120),
            'store' => mb_substr((string) $offer['store'], 0, 120),
            'price_minor' => (int) $offer['price_minor'],
            'shipping_minor' => $offer['shipping_minor'],
            'tax_minor' => $offer['tax_minor'],
            'total_minor' => (int) $offer['total_minor'],
        ];
        try {
            if (DB::table('price_observations')->where($match)->update($values) === 0) {
                DB::table('price_observations')->insert($match + $values + [
                    'id' => (string) Str::uuid7(),
                    'created_at' => now(),
                ]);
            }
        } catch (QueryException) {
            DB::table('price_observations')->where($match)->update($values);
        }
    }

    public function rememberSearch(string $query, string $country, string $currency): void
    {
        $existing = DB::table('compare_searches')
            ->whereRaw('lower(query) = lower(?)', [$query])
            ->where('country', $country)
            ->where('currency', $currency)
            ->first();
        if ($existing === null) {
            try {
                DB::transaction(function () use ($query, $country, $currency): void {
                    DB::table('compare_searches')->insert([
                        'id' => (string) Str::uuid7(),
                        'query' => $query,
                        'country' => $country,
                        'currency' => $currency,
                        'last_searched_at' => now(),
                    ]);
                });
            } catch (QueryException) {
                DB::table('compare_searches')
                    ->whereRaw('lower(query) = lower(?)', [$query])
                    ->where('country', $country)
                    ->where('currency', $currency)
                    ->update(['last_searched_at' => now()]);
            }

            return;
        }
        DB::table('compare_searches')->where('id', $existing->id)->update(['last_searched_at' => now()]);
    }

    /**
     * @return array{product_key: string, currency: string, minor_unit: int, hint: string, low_30_minor: int|null, avg_90_minor: int|null, points: list<array{date: string, low_minor: int}>}
     */
    public function chart(string $brand, string $model, ?string $size, string $currency, ?int $current): array
    {
        $key = self::productKey($brand, $model, $size);
        $rows = DB::table('price_observations')
            ->where('product_key', $key)
            ->where('observed_on', '>=', now()->subDays(90)->toDateString())
            ->orderBy('observed_on')
            ->get();
        $byDay = [];
        foreach ($rows as $row) {
            $total = $this->fx->convert((int) $row->total_minor, (string) $row->currency, $currency);
            if ($total === null) {
                continue;
            }
            $day = substr((string) $row->observed_on, 0, 10);
            $byDay[$day] = isset($byDay[$day]) ? min($byDay[$day], $total) : $total;
        }
        $points = [];
        foreach ($byDay as $day => $low) {
            $points[] = ['date' => $day, 'low_minor' => $low];
        }
        $recent = array_filter($byDay, fn (int $low, string $day): bool => $day >= now()->subDays(30)->toDateString(), ARRAY_FILTER_USE_BOTH);
        $low30 = $recent === [] ? null : min($recent);
        $avg90 = $byDay === [] ? null : (int) round(array_sum($byDay) / count($byDay));

        return [
            'product_key' => $key,
            'currency' => $currency,
            'minor_unit' => $this->fx->exponent($currency),
            'hint' => $this->hint($current, $low30, $avg90, count($byDay)),
            'low_30_minor' => $low30,
            'avg_90_minor' => $avg90,
            'points' => $points,
        ];
    }

    private function hint(?int $current, ?int $low30, ?int $avg90, int $days): string
    {
        if ($current === null || $days < 2 || $low30 === null || $avg90 === null) {
            return 'learning';
        }
        if ($current <= (int) round($low30 * 1.02)) {
            return 'good_now';
        }
        if ($current > (int) round($avg90 * 1.05)) {
            return 'wait';
        }

        return 'typical';
    }
}

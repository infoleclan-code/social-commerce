<?php

declare(strict_types=1);

namespace App\Services\Compare;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Live rates, stored as major units of the currency per 1 USD times 1_000_000.
 * A missing feed keeps the static table in config. A currency in neither place
 * is not converted.
 */
final class FxRates
{
    /** @var array<string, int> */
    private const EXPONENT = [
        'JPY' => 0,
        'KRW' => 0,
        'VND' => 0,
    ];

    public function refresh(): int
    {
        $response = Http::timeout(8)->acceptJson()->get((string) config('compare.fx_url'));
        if (! $response->successful()) {
            throw new RuntimeException('FX feed failed');
        }
        $rates = $this->parse($response->json());
        if ($rates === []) {
            throw new RuntimeException('FX feed had no rates');
        }
        $now = now();
        foreach ($rates as $currency => $perUsd) {
            DB::table('fx_rates')->updateOrInsert(
                ['currency' => $currency],
                ['per_usd' => $perUsd, 'fetched_at' => $now],
            );
        }
        $this->cached = null;

        return count($rates);
    }

    public function supports(string $currency): bool
    {
        return isset($this->table()[$currency]);
    }

    public function exponent(string $currency): int
    {
        return self::EXPONENT[$currency] ?? 2;
    }

    /** @var array<string, int>|null */
    private ?array $cached = null;

    /** @return array<string, int> */
    public function table(): array
    {
        if ($this->cached !== null) {
            return $this->cached;
        }
        $rates = [];
        foreach (config('compare.rates', []) as $currency => $perUsd) {
            if (is_string($currency) && is_numeric($perUsd) && (int) $perUsd > 0) {
                $rates[strtoupper($currency)] = (int) $perUsd;
            }
        }
        if (DB::getSchemaBuilder()->hasTable('fx_rates')) {
            foreach (DB::table('fx_rates')->get(['currency', 'per_usd']) as $row) {
                $rates[strtoupper((string) $row->currency)] = (int) $row->per_usd;
            }
        }
        $rates['USD'] = 1_000_000;

        return $this->cached = $rates;
    }

    public function convert(int $minor, string $from, string $to): ?int
    {
        $from = strtoupper($from);
        $to = strtoupper($to);
        if ($from === $to) {
            return $minor;
        }
        $rates = $this->table();
        if (! isset($rates[$from], $rates[$to])) {
            return null;
        }
        $major = $minor / (10 ** $this->exponent($from));
        $usd = $major * 1_000_000 / $rates[$from];
        $target = $usd * $rates[$to] / 1_000_000;

        return (int) round($target * (10 ** $this->exponent($to)));
    }

    /**
     * @return array<string, int>
     */
    private function parse(mixed $json): array
    {
        $rates = [];
        if (is_array($json) && isset($json['rates']) && is_array($json['rates'])) {
            foreach ($json['rates'] as $currency => $rate) {
                $this->keep($rates, (string) $currency, $rate);
            }
        }
        if (is_array($json) && array_is_list($json)) {
            foreach ($json as $row) {
                if (is_array($row)) {
                    $this->keep($rates, (string) ($row['quote'] ?? ''), $row['rate'] ?? null);
                }
            }
        }

        return $rates;
    }

    /** @param  array<string, int>  $rates */
    private function keep(array &$rates, string $currency, mixed $rate): void
    {
        $currency = strtoupper($currency);
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1 || ! is_numeric($rate) || (float) $rate <= 0) {
            return;
        }
        $rates[$currency] = (int) round(((float) $rate) * 1_000_000);
    }
}

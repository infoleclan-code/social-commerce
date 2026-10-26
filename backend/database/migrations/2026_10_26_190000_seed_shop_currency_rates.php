<?php

use App\Services\Compare\Region;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every shop currency starts with a rate so a country can be selected before
 * the first fx:refresh. The live feed replaces these numbers.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        foreach (Region::COUNTRIES as $country) {
            DB::table('fx_rates')->insertOrIgnore([
                'currency' => $country['currency'],
                'per_usd' => self::SEED[$country['currency']],
                'fetched_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('fx_rates')->whereIn('currency', array_keys(self::SEED))->delete();
    }

    /** Major units per 1 USD, times 1_000_000. fx:refresh overwrites them. */
    private const SEED = [
        'USD' => 1_000_000,
        'EUR' => 920_000,
        'GBP' => 790_000,
        'CAD' => 1_360_000,
        'MXN' => 18_000_000,
        'BRL' => 5_100_000,
        'SEK' => 10_500_000,
        'NOK' => 10_700_000,
        'DKK' => 6_900_000,
        'PLN' => 4_000_000,
        'CHF' => 880_000,
        'TRY' => 34_000_000,
        'AED' => 3_670_000,
        'INR' => 84_000_000,
        'JPY' => 151_000_000,
        'KRW' => 1_350_000_000,
        'SGD' => 1_320_000,
        'AUD' => 1_520_000,
        'NZD' => 1_660_000,
        'ZAR' => 18_000_000,
    ];
};

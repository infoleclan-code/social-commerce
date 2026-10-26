<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\Compare\CouponCatalog;
use App\Services\Compare\PriceHistory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Sample price history and a store coupon so the preview chart and offer card are populated. */
class CompareDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production') || ! Schema::hasTable('price_observations')) {
            return;
        }

        $key = PriceHistory::productKey('Nike', 'Dunk Low', '10');
        for ($daysAgo = 40; $daysAgo >= 1; $daysAgo--) {
            $observed = now()->subDays($daysAgo)->toDateString();
            $match = [
                'product_key' => $key,
                'host' => 'finishline.com',
                'currency' => 'USD',
                'observed_on' => $observed,
            ];
            $total = 16_000 - ((40 - $daysAgo) * 80);
            $values = [
                'query' => 'nike sneakers',
                'store' => 'Finish Line',
                'price_minor' => $total,
                'shipping_minor' => 0,
                'tax_minor' => null,
                'total_minor' => $total,
            ];
            if (DB::table('price_observations')->where($match)->exists()) {
                DB::table('price_observations')->where($match)->update($values);

                continue;
            }
            DB::table('price_observations')->insert($match + $values + [
                'id' => (string) Str::uuid7(),
                'created_at' => now()->subDays($daysAgo),
            ]);
        }

        $exists = DB::table('store_coupons')->where('host', 'finishline.com')->where('code', 'SAVE10')->exists();
        if (! $exists) {
            app(CouponCatalog::class)->create('finishline.com', 'SAVE10', '10% off at Finish Line', null);
        }
    }
}

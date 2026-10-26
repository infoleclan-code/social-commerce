<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Compare\CompareService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Re-checks recent searches and saved alerts so the price chart gains a point each day. */
final class SnapshotPrices extends Command
{
    protected $signature = 'prices:snapshot';

    protected $description = 'Record current offer prices for tracked searches';

    public function handle(CompareService $compare): int
    {
        $jobs = [];
        foreach (DB::table('compare_searches')->orderByDesc('last_searched_at')->limit(25)->get() as $row) {
            $jobs[$this->key($row->query, $row->country, $row->currency)] = [$row->query, $row->country, $row->currency, null, null];
        }
        foreach (DB::table('wishlists')->where('notify', true)->orderByDesc('updated_at')->limit(25)->get() as $row) {
            $country = $row->country ?: 'US';
            $jobs[$this->key($row->query, $country, $row->currency)] = [$row->query, $country, $row->currency, $row->brand, $row->size];
        }
        $done = 0;
        foreach ($jobs as [$query, $country, $currency, $brand, $size]) {
            try {
                $compare->search((string) $query, [
                    'brand' => $brand,
                    'size' => $size,
                    'min_minor' => null,
                    'max_minor' => null,
                    'in_stock' => true,
                    'sort' => 'total_asc',
                    'currency' => (string) $currency,
                    'country' => (string) $country,
                ]);
                $done++;
            } catch (Throwable) {
                // One store feed failure does not stop the rest of the snapshot.
            }
        }
        $this->info('Price snapshots: '.$done);

        return self::SUCCESS;
    }

    private function key(mixed $query, mixed $country, mixed $currency): string
    {
        return strtolower((string) $query).'|'.(string) $country.'|'.(string) $currency;
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Flash drops and drop hours are started here, on the server's schedule and limits
 * (commerce.flash_drops), never by an API request.
 */
final class RotateDrops extends Command
{
    protected $signature = 'drops:rotate';

    protected $description = 'Start the next flash drop and drop hour when none is live';

    public function handle(): int
    {
        if (! (bool) config('commerce.flash_drops.enabled')) {
            $this->info('Flash drops are disabled.');

            return self::SUCCESS;
        }
        DB::transaction(function (): void {
            DB::selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['drops:rotate']);
            $live = DB::table('flash_drops')->where('ends_at', '>', now())->where('stock_remaining', '>', 0)->exists();
            if (! $live) {
                $product = $this->pick();
                if ($product !== null) {
                    DB::table('flash_drops')->insert([
                        'id' => (string) Str::uuid7(),
                        'product_id' => $product->id,
                        'discount_bps' => max(1, min(8000, (int) config('commerce.flash_drops.discount_bps'))),
                        'stock_remaining' => min((int) config('commerce.flash_drops.max_units'), (int) $product->stock),
                        'starts_at' => now(),
                        'ends_at' => now()->addSeconds(max(10, (int) config('commerce.flash_drops.duration_seconds'))),
                        'created_at' => now(),
                    ]);
                }
            }
            $hour = DB::table('drop_hours')->where('ends_at', '>', now())->where('starts_at', '<=', now())->exists();
            if (! $hour) {
                $product = $this->pick();
                if ($product !== null) {
                    DB::table('drop_hours')->insert([
                        'id' => (string) Str::uuid7(),
                        'product_id' => $product->id,
                        'price_minor' => (int) $product->squad_price_minor,
                        'stock' => min(8, (int) $product->stock),
                        'reserved' => 0,
                        'starts_at' => now(),
                        'ends_at' => now()->addHour(),
                        'created_at' => now(),
                    ]);
                }
            }
        });
        $this->info('Drops are live.');

        return self::SUCCESS;
    }

    private function pick(): ?object
    {
        return DB::table('products')
            ->where('stock', '>', 0)
            ->where('listing_status', 'active')
            ->orderByRaw('random()')
            ->first(['id', 'stock', 'squad_price_minor']);
    }
}

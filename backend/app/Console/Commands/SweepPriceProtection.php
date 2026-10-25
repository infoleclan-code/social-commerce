<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\SkipsWhenPurchasesDisabled;
use App\Services\Commerce\PriceGuard;
use Illuminate\Console\Command;

final class SweepPriceProtection extends Command
{
    use SkipsWhenPurchasesDisabled;

    protected $signature = 'price-protection:sweep {--product= : Only orders for this product id}';

    protected $description = 'Credit price-protection differences for prices that held long enough';

    public function handle(PriceGuard $prices): int
    {
        if ($this->purchasesAreOff()) {
            return self::SUCCESS;
        }
        $product = $this->option('product');
        $count = $prices->sweep(is_string($product) && $product !== '' ? $product : null);
        $this->info("Credited {$count} order(s).");

        return self::SUCCESS;
    }
}

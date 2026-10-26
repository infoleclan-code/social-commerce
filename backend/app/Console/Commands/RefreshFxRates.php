<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Compare\FxRates;
use Illuminate\Console\Command;
use Throwable;

/** Pulls central-bank rates. A failed fetch keeps the last saved rates and the static fallback. */
final class RefreshFxRates extends Command
{
    protected $signature = 'fx:refresh';

    protected $description = 'Refresh currency rates from the FX feed';

    public function handle(FxRates $fx): int
    {
        try {
            $count = $fx->refresh();
        } catch (Throwable) {
            $this->warn('FX rates were not updated. Saved and fallback rates still apply.');

            return self::SUCCESS;
        }
        $this->info('FX rates updated: '.$count);

        return self::SUCCESS;
    }
}

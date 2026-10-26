<?php

declare(strict_types=1);

namespace App\Console\Concerns;

trait SkipsWhenPurchasesDisabled
{
    protected function purchasesAreOff(): bool
    {
        if (config('commerce.purchases_enabled')) {
            return false;
        }
        $this->info('In-app purchases are off.');

        return true;
    }
}

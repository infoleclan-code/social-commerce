<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Payments\OrderPayments;
use Illuminate\Console\Command;

final class ReconcilePayments extends Command
{
    protected $signature = 'payments:reconcile {--limit=200 : Payments per run}';

    protected $description = 'Resolve payments with an unknown outcome (re-send with the same idempotency key) and cancel abandoned 3-D Secure steps';

    public function handle(OrderPayments $payments): int
    {
        $count = $payments->reconcile(max(1, (int) $this->option('limit')));
        $this->info("Reconciled {$count} payment(s).");

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Payments\OrderPayments;
use Illuminate\Console\Command;

final class CaptureDuePayments extends Command
{
    protected $signature = 'payments:capture-due {--limit=200 : Authorizations per run}';

    protected $description = 'Capture card authorizations whose undo window, squad, or night-hold deadline has passed';

    public function handle(OrderPayments $payments): int
    {
        $count = $payments->captureDue(max(1, (int) $this->option('limit')));
        $this->info("Capture attempted for {$count} authorization(s).");

        return self::SUCCESS;
    }
}

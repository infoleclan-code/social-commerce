<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Commerce\LedgerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ReleaseCommissions extends Command
{
    protected $signature = 'ledger:release-commissions {--limit=500 : Orders per run}';

    protected $description = 'Release creator commissions (and grant the kept-purchase reward) once the 14-day return window has closed';

    public function handle(LedgerService $ledger): int
    {
        $due = DB::select(
            "SELECT o.id
               FROM orders o
              WHERE o.status = 'paid'
                AND o.delivered_at IS NOT NULL
                AND o.delivered_at < clock_timestamp() - make_interval(days => ?)
                AND o.payment_status IN ('captured', 'partially_refunded')
                AND (
                      (EXISTS (SELECT 1 FROM ledger_journals j WHERE j.order_id = o.id AND j.kind = 'commission_pending')
                       AND NOT EXISTS (SELECT 1 FROM ledger_journals j WHERE j.order_id = o.id AND j.kind = 'commission_release'))
                   OR NOT EXISTS (SELECT 1 FROM reward_events r WHERE r.kind = 'purchase' AND r.source_id = o.id)
                )
              ORDER BY o.delivered_at
              LIMIT ?",
            [LedgerService::RELEASE_AFTER_DAYS, max(1, (int) $this->option('limit'))],
        );
        $released = 0;
        foreach ($due as $row) {
            try {
                $released += $ledger->releaseKept((string) $row->id);
            } catch (Throwable $exception) {
                $this->warn('Order '.$row->id.' left for the next run ('.$exception::class.').');
            }
        }
        $this->info('Checked '.count($due)." order(s); released {$released} commission(s).");

        return self::SUCCESS;
    }
}

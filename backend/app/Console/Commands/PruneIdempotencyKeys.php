<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PruneIdempotencyKeys extends Command
{
    protected $signature = 'idempotency:prune {--hours=24 : Keep completed keys this long}';

    protected $description = 'Delete Idempotency-Key records past their replay window';

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $deleted = DB::table('idempotency_keys')->where('created_at', '<', now()->subHours($hours))->delete();
        $this->info("Pruned {$deleted} key(s).");

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes every balance from the immutable lines and compares it with ledger_balances, and checks
 * that every journal balances per currency. Exit code 1 on any difference (alert on it).
 */
final class VerifyLedger extends Command
{
    protected $signature = 'ledger:verify';

    protected $description = 'Prove ledger_balances equals the sum of ledger_lines and every journal balances';

    public function handle(): int
    {
        $drift = DB::select(
            "SELECT COALESCE(l.account, b.account) AS account, COALESCE(l.currency, b.currency) AS currency,
                    COALESCE(l.total, 0) AS from_lines, COALESCE(b.total, 0) AS stored
               FROM (SELECT account, currency, SUM(CASE WHEN direction = 'credit' THEN amount_minor ELSE -amount_minor END) AS total
                       FROM ledger_lines GROUP BY account, currency) l
               FULL JOIN (SELECT account, currency, SUM(balance_minor) AS total FROM ledger_balances GROUP BY account, currency) b
                 ON b.account = l.account AND b.currency = l.currency
              WHERE COALESCE(l.total, 0) <> COALESCE(b.total, 0)",
        );
        $unbalanced = DB::select(
            "SELECT journal_id, currency FROM ledger_lines
              GROUP BY journal_id, currency
             HAVING SUM(CASE WHEN direction = 'credit' THEN amount_minor ELSE -amount_minor END) <> 0
              LIMIT 50",
        );
        foreach ($drift as $row) {
            $this->error("{$row->account} {$row->currency}: lines {$row->from_lines}, stored {$row->stored}");
        }
        foreach ($unbalanced as $row) {
            $this->error("journal {$row->journal_id} does not balance in {$row->currency}");
        }
        if ($drift === [] && $unbalanced === []) {
            $this->info('Ledger verified: balances match the lines and every journal balances.');

            return self::SUCCESS;
        }

        return self::FAILURE;
    }
}

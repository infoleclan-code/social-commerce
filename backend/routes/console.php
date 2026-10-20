<?php

declare(strict_types=1);

use App\Services\Commerce\ScanCatalogSeed;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('catalog:embed-scans', function (): void {
    app(ScanCatalogSeed::class)->run();
    $this->info('Scan embeddings stored.');
})->purpose('Store visual embeddings for catalog photos');

/*
|--------------------------------------------------------------------------
| Schedule (run `php artisan schedule:work` locally, a cron calling schedule:run in production)
|--------------------------------------------------------------------------
| Payments are captured, reconciled and settled here, never inside a GET request. Every job is
| idempotent (keys in the ledger and at Stripe) and claims its rows with FOR UPDATE SKIP LOCKED, so
| overlapping runs or several servers cannot double-process; onOneServer() is an extra guard.
| Reel media is deleted with the account (EraseUserMedia).
*/

Schedule::command('payments:capture-due')->everyMinute()->withoutOverlapping(5)->onOneServer();
Schedule::command('payments:reconcile')->everyMinute()->withoutOverlapping(5)->onOneServer();
Schedule::command('ledger:release-commissions')->hourly()->withoutOverlapping(30)->onOneServer();
Schedule::command('price-protection:sweep')->hourly()->withoutOverlapping(30)->onOneServer();
Schedule::command('gifts:refund-expired')->hourly()->withoutOverlapping(30)->onOneServer();
Schedule::command('drops:rotate')->everyTenMinutes()->withoutOverlapping(5)->onOneServer();
Schedule::command('idempotency:prune')->daily()->onOneServer();
Schedule::command('ledger:verify')->dailyAt('03:17')->onOneServer();

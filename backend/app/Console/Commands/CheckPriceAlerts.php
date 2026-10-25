<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\PriceDropAlert;
use App\Models\User;
use App\Services\Compare\CompareService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Re-checks saved searches and emails (and pushes) when the best in-stock total drops. */
final class CheckPriceAlerts extends Command
{
    protected $signature = 'prices:check-alerts';

    protected $description = 'Notify people when a saved product\'s best price drops';

    public function handle(CompareService $compare): int
    {
        $sent = 0;
        foreach (DB::table('wishlists')->where('notify', true)->orderBy('id')->get() as $row) {
            try {
                $result = $compare->search((string) $row->query, [
                    'brand' => $row->brand,
                    'size' => $row->size,
                    'min_minor' => null,
                    'max_minor' => null,
                    'in_stock' => true,
                    'sort' => 'total_asc',
                    'currency' => (string) $row->currency,
                ]);
            } catch (Throwable) {
                continue;
            }
            $best = $result['products'][0]['offers'][0]['total_minor'] ?? null;
            if (! is_int($best)) {
                continue;
            }
            $previous = $row->best_total_minor !== null ? (int) $row->best_total_minor : null;
            DB::table('wishlists')->where('id', $row->id)->update([
                'best_total_minor' => $best,
                'updated_at' => now(),
            ]);
            if ($previous === null || $best >= $previous) {
                continue;
            }
            $user = User::query()->find($row->user_id);
            if ($user === null || $user->anonymized_at !== null) {
                continue;
            }
            try {
                Mail::to($user->email)->send(new PriceDropAlert((string) $row->query, (string) $row->currency, $best));
                $sent++;
            } catch (Throwable) {
                // The stored price is already updated; a mail outage can retry next hour.
            }
            $this->push($user->id, (string) $row->query, (string) $row->currency, $best);
        }
        $this->info('Price alerts sent: '.$sent);

        return self::SUCCESS;
    }

    private function push(string $userId, string $query, string $currency, int $total): void
    {
        $tokens = DB::table('user_devices')
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->whereNotNull('expo_push_token')
            ->pluck('expo_push_token');
        foreach ($tokens as $token) {
            try {
                Http::timeout(4)->post('https://exp.host/--/api/v2/push/send', [
                    'to' => $token,
                    'title' => 'Price drop',
                    'body' => $query.' is now '.$currency.' '.number_format($total / 100, 2),
                ]);
            } catch (Throwable) {
                // Push is best-effort. The token is not written to the log.
            }
        }
    }
}

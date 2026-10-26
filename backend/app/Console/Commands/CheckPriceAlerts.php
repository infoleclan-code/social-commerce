<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\PriceDropAlert;
use App\Models\User;
use App\Services\Compare\CompareService;
use App\Services\Compare\FxRates;
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

    public function handle(CompareService $compare, FxRates $fx): int
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
                    'country' => $row->country ?: 'US',
                ]);
            } catch (Throwable) {
                continue;
            }
            $best = $result['products'][0]['offers'][0]['total_minor'] ?? null;
            if (! is_int($best)) {
                continue;
            }
            $previous = $row->best_total_minor !== null ? (int) $row->best_total_minor : null;
            $target = $row->target_minor !== null ? (int) $row->target_minor : null;
            $dropped = $previous !== null && $best < $previous;
            $hitTarget = $target !== null && $best <= $target && ($previous === null || $previous > $target);
            $remember = function () use ($row, $best): void {
                DB::table('wishlists')->where('id', $row->id)->update([
                    'best_total_minor' => $best,
                    'updated_at' => now(),
                ]);
            };
            if (! $dropped && ! $hitTarget) {
                $remember();

                continue;
            }
            $user = User::query()->find($row->user_id);
            if ($user === null || $user->anonymized_at !== null) {
                $remember();

                continue;
            }
            $reason = $hitTarget && ! $dropped ? 'target' : 'drop';
            $exponent = $fx->exponent((string) $row->currency);
            try {
                Mail::to($user->email)->send(new PriceDropAlert((string) $row->query, (string) $row->currency, $best, $reason, $exponent));
            } catch (Throwable) {
                continue;
            }
            $this->push($user->id, (string) $row->query, (string) $row->currency, $best, $reason, $exponent);
            $remember();
            $sent++;
        }
        $this->info('Price alerts sent: '.$sent);

        return self::SUCCESS;
    }

    private function amount(int $total, int $exponent): string
    {
        $exponent = max(0, $exponent);

        return number_format($total / (10 ** $exponent), $exponent, '.', '');
    }

    private function push(string $userId, string $query, string $currency, int $total, string $reason, int $exponent): void
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
                    'title' => $reason === 'target' ? 'Target price' : 'Price drop',
                    'body' => $query.' is now '.$currency.' '.$this->amount($total, $exponent),
                ]);
            } catch (Throwable) {
                // Push is best-effort. The token is not written to the log.
            }
        }
    }
}

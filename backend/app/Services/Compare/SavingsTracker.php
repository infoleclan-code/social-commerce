<?php

declare(strict_types=1);

namespace App\Services\Compare;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Savings versus the highest offer in the same top 3. No payment is taken. */
final class SavingsTracker
{
    /** @param  array<string, mixed>  $offer */
    public function record(User $user, array $offer): void
    {
        if (! is_numeric($offer['price_minor'] ?? null) || ! is_numeric($offer['highest_minor'] ?? null)) {
            return;
        }
        $paid = (int) $offer['price_minor'];
        $highest = (int) $offer['highest_minor'];
        $currency = strtoupper((string) ($offer['currency'] ?? 'USD'));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            return;
        }
        DB::table('savings_events')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $user->id,
            'offer_key' => mb_substr((string) ($offer['offer_key'] ?? ''), 0, 24),
            'store' => mb_substr((string) ($offer['store'] ?? 'Store'), 0, 120),
            'currency' => $currency,
            'paid_minor' => $paid,
            'highest_minor' => $highest,
            'saved_minor' => max(0, $highest - $paid),
            'created_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    public function summary(User $user): array
    {
        $rows = DB::table('savings_events')->where('user_id', $user->id)->orderBy('created_at')->get();
        $currency = $rows->isEmpty() ? 'USD' : strtoupper(trim((string) $rows->first()->currency));
        $saved = 0;
        $month = 0;
        $start = now()->startOfMonth();
        foreach ($rows as $row) {
            if (strtoupper(trim((string) $row->currency)) !== $currency) {
                continue;
            }
            $saved += (int) $row->saved_minor;
            if (Carbon::parse((string) $row->created_at)->greaterThanOrEqualTo($start)) {
                $month += (int) $row->saved_minor;
            }
        }
        $clicks = $rows->count();
        $badges = [];
        if ($clicks >= 1) {
            $badges[] = ['id' => 'first_click', 'label' => 'First store visit'];
        }
        if ($saved >= 1_000) {
            $badges[] = ['id' => 'saver', 'label' => 'Saved 10 dollars'];
        }
        if ($clicks >= 5) {
            $badges[] = ['id' => 'regular', 'label' => 'Five store visits'];
        }
        if ($month >= 2_000) {
            $badges[] = ['id' => 'monthly', 'label' => 'Monthly milestone'];
        }

        return [
            'currency' => $currency,
            'minor_unit' => 2,
            'clicks' => $clicks,
            'saved_minor' => $saved,
            'month_saved_minor' => $month,
            'badges' => $badges,
            'note' => 'Savings are the gap between the offer you opened and the highest offer in that top 3. The purchase still happens on the store site.',
        ];
    }
}

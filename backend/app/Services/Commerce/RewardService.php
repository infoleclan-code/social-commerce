<?php

declare(strict_types=1);

namespace App\Services\Commerce;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Support\Currency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Coins and XP. Engagement rewards are granted once per source and capped per day, so a script that
 * likes or "watches" thousands of posts cannot mint loot-box coupons. Cash is never granted here:
 * money moves only through the ledger (user_wallets.cash_minor is written by a ledger trigger).
 */
final class RewardService
{
    /** Engagement rewards per user and UTC day. */
    public const DAILY_LIMITS = ['watch' => 20, 'like' => 40, 'comment' => 20];

    private const ONCE_PER_SOURCE = ['watch', 'like', 'comment', 'purchase'];

    public function grant(User $user, string $kind, ?string $sourceId, int $coins, int $xp, int $cashMinor = 0): bool
    {
        if ($cashMinor !== 0) {
            throw new LogicException('Cash is credited only through the ledger.');
        }

        return DB::transaction(function () use ($user, $kind, $sourceId, $coins, $xp): bool {
            if (isset(self::DAILY_LIMITS[$kind])) {
                DB::selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['reward:'.$user->id.':'.$kind]);
                $today = (int) DB::table('reward_events')
                    ->where('user_id', $user->id)
                    ->where('kind', $kind)
                    ->where('created_at', '>=', now()->utc()->startOfDay())
                    ->count();
                if ($today >= self::DAILY_LIMITS[$kind]) {
                    return false;
                }
            }
            $row = [
                'id' => (string) Str::uuid7(),
                'user_id' => $user->id,
                'kind' => $kind,
                'coins' => $coins,
                'xp' => $xp,
                'cash_minor' => 0,
                'source_id' => $sourceId,
                'created_at' => now(),
            ];
            if ($sourceId !== null && in_array($kind, self::ONCE_PER_SOURCE, true)) {
                if (DB::table('reward_events')->insertOrIgnore($row) !== 1) {
                    return false;
                }
            } else {
                DB::table('reward_events')->insert($row);
            }
            $this->bump($user->id, $coins, $xp);

            return true;
        });
    }

    /** @return array<string, mixed> */
    public function wallet(User $user): array
    {
        $wallet = DB::table('user_wallets')->where('user_id', $user->id)->first();
        $coupons = DB::table('coupons')
            ->where('user_id', $user->id)
            ->whereNull('redeemed_at')
            ->where('expires_at', '>', now())
            ->orderBy('expires_at')
            ->get()
            ->map(fn (object $row): array => [
                'id' => $row->id,
                'label' => $row->label,
                'discount_bps' => (int) $row->discount_bps,
                'expires_at' => (string) $row->expires_at,
            ])
            ->all();

        return [
            'coins' => (int) ($wallet->coins ?? 0),
            'xp' => (int) ($wallet->xp ?? 0),
            'cash' => Currency::money((int) ($wallet->cash_minor ?? 0), (string) ($wallet->currency ?? 'USD')),
            'coupons' => $coupons,
        ];
    }

    /** @return array<string, mixed> */
    public function openLootBox(User $user): array
    {
        return DB::transaction(function () use ($user): array {
            $wallet = DB::table('user_wallets')->where('user_id', $user->id)->lockForUpdate()->first();
            $coins = (int) ($wallet->coins ?? 0);
            if ($coins < 100) {
                throw ApiException::notEnoughCoins();
            }
            DB::table('user_wallets')->where('user_id', $user->id)->update([
                'coins' => $coins - 100,
                'updated_at' => now(),
            ]);
            $bps = [1000, 1500, 2000, 3000][random_int(0, 3)];
            $id = (string) Str::uuid7();
            DB::table('coupons')->insert([
                'id' => $id,
                'user_id' => $user->id,
                'label' => ($bps / 100).'% off',
                'discount_bps' => $bps,
                'expires_at' => now()->addDays(7),
                'created_at' => now(),
            ]);
            DB::table('reward_events')->insert([
                'id' => (string) Str::uuid7(),
                'user_id' => $user->id,
                'kind' => 'loot',
                'coins' => -100,
                'xp' => 0,
                'cash_minor' => 0,
                'source_id' => $id,
                'created_at' => now(),
            ]);

            return [
                'coupon' => [
                    'id' => $id,
                    'label' => ($bps / 100).'% off',
                    'discount_bps' => $bps,
                ],
                'coins' => $coins - 100,
            ];
        });
    }

    /**
     * A completed watch earns once per post and is capped per day. The duration is the server's
     * (media_duration_ms of the stored video), not the client's, and an author earns nothing on their
     * own posts.
     */
    public function recordWatch(User $user, string $postId, int $watchedMs, int $durationMs): bool
    {
        $post = DB::table('feed_posts')->where('id', $postId)->first(['user_id', 'media_duration_ms']);
        if ($post === null || (string) $post->user_id === (string) $user->id) {
            return false;
        }
        $serverDuration = $post->media_duration_ms !== null ? (int) $post->media_duration_ms : $durationMs;
        if ($serverDuration < 1 || $watchedMs * 100 < $serverDuration * 80) {
            return false;
        }
        $granted = $this->grant($user, 'watch', $postId, 15, 10);
        if ($granted) {
            DB::table('feed_posts')->where('id', $postId)->increment('watch_completions');
        }

        return $granted;
    }

    private function bump(string $userId, int $coins, int $xp): void
    {
        DB::statement(
            <<<'SQL'
            INSERT INTO user_wallets (user_id, coins, xp, currency, updated_at)
            VALUES (?, GREATEST(?, 0), GREATEST(?, 0), 'USD', now())
            ON CONFLICT (user_id) DO UPDATE SET
              coins = user_wallets.coins + EXCLUDED.coins,
              xp = user_wallets.xp + EXCLUDED.xp,
              updated_at = now()
            SQL,
            [$userId, $coins, $xp],
        );
    }
}

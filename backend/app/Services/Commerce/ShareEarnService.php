<?php

declare(strict_types=1);

namespace App\Services\Commerce;

use App\Models\User;
use App\Support\Currency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tracked /s/{token} links and an append-only commission ledger.
 * The sharer earns when a different person checks out through the token.
 */
final class ShareEarnService
{
    public function __construct(
        private readonly RewardService $rewards,
        private readonly LedgerService $ledger,
    ) {}

    /** @return array{token: string, url: string, commission_bps: int}|null */
    public function mint(User $sharer, string $postId): ?array
    {
        $tag = DB::table('reel_product_tags')->where('post_id', $postId)->first();
        if ($tag === null) {
            return null;
        }
        $existing = DB::table('referral_links')
            ->where('user_id', $sharer->id)
            ->where('post_id', $postId)
            ->where('product_id', $tag->product_id)
            ->first();
        if ($existing === null) {
            $token = Str::random(16);
            DB::table('referral_links')->insert([
                'id' => (string) Str::uuid7(),
                'token' => $token,
                'user_id' => $sharer->id,
                'post_id' => $postId,
                'product_id' => $tag->product_id,
                'commission_bps' => (int) $tag->commission_bps,
                'created_at' => now(),
            ]);
        } else {
            $token = (string) $existing->token;
        }
        $base = rtrim((string) config('app.url'), '/');

        return [
            'token' => $token,
            'url' => "{$base}/s/{$token}",
            'commission_bps' => (int) ($existing->commission_bps ?? $tag->commission_bps),
        ];
    }

    /** @return array<string, mixed>|null */
    public function open(string $token, ?User $viewer): ?array
    {
        $link = DB::table('referral_links')->where('token', $token)->first();
        if ($link === null) {
            return null;
        }
        DB::table('referral_clicks')->insert([
            'id' => (string) Str::uuid7(),
            'referral_link_id' => $link->id,
            'viewer_id' => $viewer?->id,
            'created_at' => now(),
        ]);
        $product = DB::table('products')->where('id', $link->product_id)->first();

        return [
            'token' => $link->token,
            'post_id' => $link->post_id,
            'commission_bps' => (int) $link->commission_bps,
            'product' => $product === null ? null : [
                'id' => $product->id,
                'title' => $product->title,
                'price' => $this->money((int) $product->price_minor, (string) $product->currency),
                'squad_price' => $this->money((int) $product->squad_price_minor, (string) $product->currency),
            ],
        ];
    }

    /**
     * The sharer's commission on a captured order bought through their link. Paid from what is left of
     * the order's commission budget after the creator's share, at most once per order and link.
     */
    public function earnForOrder(object $order): void
    {
        if ($order->referral_link_id === null) {
            return;
        }
        $link = DB::table('referral_links')->where('id', $order->referral_link_id)->first();
        if ($link === null || (string) $link->product_id !== (string) $order->product_id
            || (string) $link->user_id === (string) $order->buyer_id || (string) $link->user_id === (string) $order->creator_id) {
            return;
        }
        $total = (int) $order->total_minor;
        $budget = intdiv($total * (int) config('commerce.max_commission_bps'), 10000) - (int) $order->creator_commission_minor;
        $amount = min(intdiv($total * (int) $link->commission_bps, 10000), max(0, $budget));
        if ($amount < 1) {
            return;
        }
        $inserted = DB::table('commission_ledger')->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'user_id' => $link->user_id,
            'referral_link_id' => $link->id,
            'order_id' => $order->id,
            'entry' => 'earn',
            'amount_minor' => $amount,
            'currency' => $order->currency,
            'created_at' => now(),
        ]);
        if ($inserted === 1) {
            $this->ledger->pendCommission((string) $link->user_id, (string) $order->id, $amount, (string) $order->currency);
        }
    }

    /** @return array<string, mixed> */
    public function ledger(User $user): array
    {
        $rows = DB::table('commission_ledger')->where('user_id', $user->id)->orderByDesc('created_at')->limit(20)->get();
        $earned = DB::table('commission_ledger')
            ->where('user_id', $user->id)
            ->where('entry', 'earn')
            ->groupBy('currency')
            ->orderBy('currency')
            ->get(['currency', DB::raw('SUM(amount_minor) AS amount')]);
        $clicks = (int) DB::table('referral_clicks')
            ->whereIn('referral_link_id', function ($query) use ($user): void {
                $query->select('id')->from('referral_links')->where('user_id', $user->id);
            })
            ->count();
        $first = $earned->first();

        return [
            'earned' => $this->money($first === null ? 0 : (int) $first->amount, $first === null ? 'USD' : (string) $first->currency),
            'earned_by_currency' => $earned->map(fn (object $row): array => $this->money((int) $row->amount, (string) $row->currency))->all(),
            'pending' => $this->money($this->ledger->balance(LedgerService::pending((string) $user->id), 'USD'), 'USD'),
            'available' => $this->money($this->ledger->balance(LedgerService::available((string) $user->id), 'USD'), 'USD'),
            'clicks' => $clicks,
            'entries' => $rows->map(fn (object $row): array => [
                'id' => $row->id,
                'entry' => $row->entry,
                'amount' => $this->money((int) $row->amount_minor, (string) $row->currency),
                'order_id' => $row->order_id,
                'created_at' => (string) $row->created_at,
            ])->all(),
        ];
    }

    /** @return array{amount_minor: int, currency: string, minor_unit: int} */
    private function money(int $minor, string $currency): array
    {
        return Currency::money($minor, $currency);
    }
}

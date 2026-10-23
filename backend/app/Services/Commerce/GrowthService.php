<?php

declare(strict_types=1);

namespace App\Services\Commerce;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\Payments\OrderPayments;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Opt-in live moments, rooms, a capped shopping agent, scheduled shows,
 * city unlocks, seasons, and the trust layer. Live activities are never ads.
 */
final class GrowthService
{
    /** @return list<array<string, mixed>> */
    public function activities(User $user): array
    {
        return DB::table('live_activities')
            ->where('user_id', $user->id)
            ->where('ends_at', '>', now())
            ->orderBy('ends_at')
            ->get()
            ->map(fn (object $row): array => [
                'id' => $row->id,
                'kind' => $row->kind,
                'title' => $row->title,
                'body' => $row->body,
                'action' => $row->action,
                'ends_at' => (string) $row->ends_at,
            ])->all();
    }

    public function track(User $user, string $kind, string $subjectId, string $title, string $body, string $action, string $endsAt): void
    {
        if (! in_array($kind, ['auction', 'squad', 'drop', 'order'], true)) {
            return;
        }
        DB::table('live_activities')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $user->id,
            'kind' => $kind,
            'subject_id' => $subjectId,
            'title' => mb_substr($title, 0, 80),
            'body' => mb_substr($body, 0, 160),
            'action' => $action,
            'ends_at' => $endsAt,
            'created_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    public function openRoom(User $user, ?string $postId, string $visibility): array
    {
        $token = Str::random(16);
        $id = (string) Str::uuid7();
        DB::table('shop_rooms')->insert([
            'id' => $id,
            'host_id' => $user->id,
            'post_id' => $postId,
            'token' => $token,
            'visibility' => $visibility === 'public' ? 'public' : 'private',
            'ends_at' => now()->addHours(2),
            'created_at' => now(),
        ]);
        DB::table('shop_room_members')->insert([
            'room_id' => $id,
            'user_id' => $user->id,
            'joined_at' => now(),
        ]);

        return $this->room($token, $user);
    }

    /** @return array<string, mixed> */
    public function joinRoom(User $user, string $token): array
    {
        $room = DB::table('shop_rooms')->where('token', $token)->first();
        if ($room === null || now()->greaterThan($room->ends_at)) {
            throw ApiException::giftClosed();
        }
        DB::table('shop_room_members')->insertOrIgnore([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'joined_at' => now(),
        ]);

        return $this->room($token, $user);
    }

    /** @return array<string, mixed> */
    public function voteRoom(User $user, string $token, string $choice): array
    {
        $room = $this->memberRoom($user, $token);
        DB::table('shop_room_votes')->updateOrInsert(
            ['room_id' => $room->id, 'user_id' => $user->id],
            ['choice' => $choice, 'created_at' => now()],
        );

        return $this->room($token, $user);
    }

    /** @return array<string, mixed> */
    public function saveBrief(User $user, int $budget, int $cap, string $styles, int $alerts, bool $allowBid, bool $allowSquad): array
    {
        DB::table('agent_briefs')->updateOrInsert(
            ['user_id' => $user->id],
            [
                'budget_minor' => $budget,
                'monthly_cap_minor' => $cap,
                'styles' => mb_substr($styles, 0, 200),
                'alerts_per_day' => max(0, min(5, $alerts)),
                'allow_bid' => $allowBid,
                'allow_squad' => $allowSquad,
                'updated_at' => now(),
            ],
        );

        return $this->brief($user);
    }

    /** @return array<string, mixed> */
    public function brief(User $user): array
    {
        $brief = DB::table('agent_briefs')->where('user_id', $user->id)->first();
        $alerts = DB::table('agent_alerts')->where('user_id', $user->id)->orderByDesc('created_at')->limit(5)->get();

        return [
            'labelled_ai' => true,
            'brief' => $brief === null ? null : [
                'budget_minor' => (int) $brief->budget_minor,
                'monthly_cap_minor' => (int) $brief->monthly_cap_minor,
                'styles' => $brief->styles,
                'alerts_per_day' => (int) $brief->alerts_per_day,
                'allow_bid' => (bool) $brief->allow_bid,
                'allow_squad' => (bool) $brief->allow_squad,
            ],
            'alerts' => $alerts->map(fn (object $row): array => [
                'id' => $row->id,
                'body' => $row->body,
                'status' => $row->status,
            ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function propose(User $user): array
    {
        $brief = DB::table('agent_briefs')->where('user_id', $user->id)->first();
        if ($brief === null) {
            throw ApiException::missionIncomplete();
        }
        $today = (int) DB::table('agent_alerts')->where('user_id', $user->id)->where('created_at', '>=', now()->startOfDay())->count();
        if ($today >= (int) $brief->alerts_per_day) {
            return ['capped' => true, 'alert' => null];
        }
        $product = DB::table('products')->where('price_minor', '<=', (int) $brief->budget_minor)->where('stock', '>', 0)->orderBy('price_minor')->first();
        if ($product === null) {
            return ['capped' => false, 'alert' => null];
        }
        $id = (string) Str::uuid7();
        DB::table('agent_alerts')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'body' => 'AI find: '.$product->title.' is inside the budget you set.',
            'status' => 'pending',
            'created_at' => now(),
        ]);

        return ['capped' => false, 'alert' => ['id' => $id, 'body' => 'AI find: '.$product->title.' is inside the budget you set.', 'status' => 'pending']];
    }

    public function undoAlert(User $user, string $alertId): void
    {
        DB::table('agent_alerts')->where('id', $alertId)->where('user_id', $user->id)->where('status', 'pending')->update(['status' => 'undone']);
    }

    /** @return list<array<string, mixed>> */
    public function shows(): array
    {
        $this->ensureShow();

        return DB::table('live_shows')->where('ends_at', '>', now())->orderBy('starts_at')->get()->map(fn (object $row): array => [
            'id' => $row->id,
            'title' => $row->title,
            'category' => $row->category,
            'region' => $row->region,
            'starts_at' => (string) $row->starts_at,
            'ends_at' => (string) $row->ends_at,
        ])->all();
    }

    public function remind(User $user, string $showId): void
    {
        $show = DB::table('live_shows')->where('id', $showId)->first();
        if ($show === null) {
            return;
        }
        DB::table('show_reminders')->insertOrIgnore([
            'show_id' => $showId,
            'user_id' => $user->id,
            'created_at' => now(),
        ]);
        $this->track($user, 'drop', $showId, $show->title, 'One reminder. The show ends.', 'open', (string) $show->ends_at);
    }

    /** @return array<string, mixed> */
    public function joinPlace(User $user, string $placeId): array
    {
        $place = DB::table('places')->where('id', $placeId)->first();
        if ($place === null) {
            throw ApiException::profileNotFound();
        }
        $count = (int) DB::table('place_members')->where('place_id', $placeId)->count();
        DB::table('place_members')->insertOrIgnore([
            'place_id' => $placeId,
            'user_id' => $user->id,
            'founding' => $place->unlocked_at === null,
            'joined_at' => now(),
        ]);
        $count = (int) DB::table('place_members')->where('place_id', $placeId)->count();
        if ($place->unlocked_at === null && $count >= (int) $place->member_goal) {
            DB::table('places')->where('id', $placeId)->update(['unlocked_at' => now()]);
        }

        return $this->places();
    }

    /** @return list<array<string, mixed>> */
    public function places(): array
    {
        return DB::table('places')->orderBy('name')->get()->map(function (object $place): array {
            return [
                'id' => $place->id,
                'name' => $place->name,
                'kind' => $place->kind,
                'members' => (int) DB::table('place_members')->where('place_id', $place->id)->count(),
                'goal' => (int) $place->member_goal,
                'unlocked' => $place->unlocked_at !== null,
            ];
        })->all();
    }

    /** @return array<string, mixed> */
    public function reputation(User $user): array
    {
        $this->awardTrend($user);
        $row = DB::table('users')->where('id', $user->id)->first(['trend_hunter_points', 'trend_hunter_level']);

        return [
            'points' => (int) $row->trend_hunter_points,
            'level' => (int) $row->trend_hunter_level,
            'share_bps' => 800 + ((int) $row->trend_hunter_level * 100),
        ];
    }

    /** @return array<string, mixed> */
    public function season(User $user): array
    {
        $season = DB::table('seasons')->where('starts_on', '<=', now()->toDateString())->where('ends_on', '>=', now()->toDateString())->first();
        if ($season === null) {
            return ['season' => null, 'quests' => []];
        }
        $quests = DB::table('season_quests')->where('season_id', $season->id)->get();

        return [
            'season' => ['name' => $season->name, 'ends_on' => (string) $season->ends_on, 'paid' => false],
            'quests' => $quests->map(function (object $quest) use ($user): array {
                $progress = DB::table('quest_progress')->where('quest_id', $quest->id)->where('user_id', $user->id)->first();

                return [
                    'id' => $quest->id,
                    'title' => $quest->title,
                    'target' => (int) $quest->target,
                    'progress' => (int) ($progress->progress ?? 0),
                    'reward' => 'profile cosmetic',
                ];
            })->all(),
        ];
    }

    public function advanceQuest(User $user, string $questId): void
    {
        $quest = DB::table('season_quests')->where('id', $questId)->first();
        if ($quest === null) {
            return;
        }
        $row = DB::table('quest_progress')->where('quest_id', $questId)->where('user_id', $user->id)->first();
        $next = min((int) $quest->target, (int) ($row->progress ?? 0) + 1);
        DB::table('quest_progress')->updateOrInsert(
            ['quest_id' => $questId, 'user_id' => $user->id],
            ['progress' => $next, 'completed_at' => $next >= (int) $quest->target ? now() : null],
        );
    }

    /** @return array<string, mixed> */
    public function proof(string $productId): array
    {
        $verified = (int) DB::table('orders')->where('product_id', $productId)->where('status', 'paid')->count();
        $hauls = (int) DB::table('hauls')->where('published', true)->count();

        return [
            'verified_purchases' => $verified,
            'public_hauls' => $hauls,
            'purchases_private_until_posted' => true,
        ];
    }

    public function publishHaul(User $user, string $orderId, string $caption): void
    {
        $order = DB::table('orders')->where('id', $orderId)->where('buyer_id', $user->id)->where('status', 'paid')->first();
        if ($order === null) {
            throw ApiException::notCancellable();
        }
        DB::table('orders')->where('id', $orderId)->update(['share_publicly' => true]);
        DB::table('hauls')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $user->id,
            'caption' => $caption,
            'published' => true,
            'created_at' => now(),
        ]);
        DB::table('activity_events')->insert([
            'id' => (string) Str::uuid7(),
            'actor_id' => $user->id,
            'kind' => 'purchase',
            'product_id' => $order->product_id,
            'post_id' => $order->post_id,
            'created_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    public function trust(User $user): array
    {
        $limit = DB::table('spend_limits')->where('user_id', $user->id)->first();
        $flags = DB::table('users')->where('id', $user->id)->first(['paused_at', 'deletion_requested_at', 'streaks_opt_in', 'trend_hunter_level']);
        $month = (int) DB::table('orders')->where('buyer_id', $user->id)->where('status', 'paid')->where('created_at', '>=', now()->startOfMonth())->sum('total_minor');
        $undos = (int) DB::table('orders')->where('buyer_id', $user->id)->where('status', 'cancelled')->where('created_at', '>=', now()->subDays(30))->sum('total_minor');
        $gmv = (int) DB::table('orders')->where('buyer_id', $user->id)->where('status', 'paid')->where('created_at', '>=', now()->subDays(30))->sum('total_minor');

        return [
            'monthly_spent_minor' => $month,
            'monthly_limit_minor' => $limit->monthly_limit_minor ?? null,
            'night_hold' => $limit === null || (bool) $limit->night_hold,
            'paused' => $flags->paused_at !== null,
            'deletion_requested' => $flags->deletion_requested_at !== null,
            'streaks_opt_in' => (bool) $flags->streaks_opt_in,
            'regret_adjusted_gmv_minor' => max(0, $gmv - $undos),
            'mutes' => DB::table('notification_mutes')->where('user_id', $user->id)->pluck('channel')->all(),
        ];
    }

    public function setLimit(User $user, ?int $limitMinor, bool $nightHold): void
    {
        DB::table('spend_limits')->updateOrInsert(
            ['user_id' => $user->id],
            ['monthly_limit_minor' => $limitMinor, 'night_hold' => $nightHold],
        );
    }

    public function setStreaks(User $user, bool $on): void
    {
        DB::table('users')->where('id', $user->id)->update(['streaks_opt_in' => $on]);
    }

    public function mute(User $user, string $channel): void
    {
        DB::table('notification_mutes')->insertOrIgnore([
            'user_id' => $user->id,
            'channel' => $channel,
            'muted_at' => now(),
        ]);
    }

    public function pause(User $user): void
    {
        DB::table('users')->where('id', $user->id)->update(['paused_at' => now()]);
    }

    public function unpause(User $user): void
    {
        $row = DB::table('users')->where('id', $user->id)->first(['deletion_requested_at', 'anonymized_at']);
        if ($row === null || $row->anonymized_at !== null || $row->deletion_requested_at !== null) {
            throw ApiException::accountPaused();
        }
        DB::table('users')->where('id', $user->id)->update(['paused_at' => null]);
    }

    /** Records the request and pauses purchases. anonymize_user() runs after 14 days (accounts:anonymize-due). */
    public function requestDeletion(User $user): void
    {
        DB::table('users')->where('id', $user->id)->update(['deletion_requested_at' => now(), 'paused_at' => now()]);
    }

    /** @return array<string, mixed> */
    public function export(User $user): array
    {
        $id = (string) Str::uuid7();
        DB::table('account_exports')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'status' => 'ready',
            'created_at' => now(),
        ]);
        $me = DB::table('users')->where('id', $user->id)->first(['id', 'username', 'display_name', 'email', 'locale', 'country_code']);

        return [
            'export_id' => $id,
            'user' => [
                'id' => $me->id,
                'username' => $me->username,
                'display_name' => $me->display_name,
                'email' => $me->email,
                'locale' => $me->locale,
                'country_code' => $me->country_code,
            ],
            'consents' => DB::table('user_consents')->where('user_id', $user->id)->orderBy('created_at')->get(['purpose', 'granted', 'policy_version', 'source', 'created_at']),
            'devices' => DB::table('user_devices')->where('user_id', $user->id)->orderBy('created_at')->get(['id', 'platform', 'app_version', 'push_enabled', 'last_seen_at', 'revoked_at', 'created_at']),
            'posts' => DB::table('feed_posts')->where('user_id', $user->id)->get(['id', 'caption', 'audience', 'created_at']),
            'comments' => DB::table('feed_post_comments')->where('user_id', $user->id)->get(['id', 'post_id', 'body', 'created_at']),
            'likes' => DB::table('feed_post_likes')->where('user_id', $user->id)->get(['post_id', 'created_at']),
            'connections' => DB::table('social_connections')
                ->where(fn ($query) => $query->where('requester_id', $user->id)->orWhere('addressee_id', $user->id))
                ->get(['requester_id', 'addressee_id', 'status', 'created_at']),
            'orders' => DB::table('orders')->where('buyer_id', $user->id)->get(['id', 'product_id', 'quantity', 'total_minor', 'currency', 'status', 'created_at']),
            'closet' => DB::table('closet_items')->where('user_id', $user->id)->get(['id', 'product_id', 'status']),
        ];
    }

    /**
     * The 14-day right of withdrawal. Before capture the hold is voided; after capture the remaining
     * amount is refunded through Stripe (idempotently) and the ledger reverses commissions and price
     * protection in proportion. Orders that predate real payments (purpose legacy) are only cancelled.
     */
    public function withdraw(User $user, string $orderId): void
    {
        $order = Str::isUuid($orderId) ? DB::table('orders')->where('id', $orderId)->where('buyer_id', $user->id)->first() : null;
        if ($order === null || $order->status === 'cancelled') {
            throw ApiException::notCancellable();
        }
        if ($order->delivered_at !== null && now()->greaterThan(now()->parse($order->delivered_at)->addDays(14))) {
            throw ApiException::notCancellable();
        }
        $payments = app(OrderPayments::class);
        if ($order->purpose === 'legacy' || $order->stripe_payment_intent_id === null && $order->status === 'paid') {
            DB::transaction(function () use ($orderId): void {
                $locked = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
                if ($locked === null || $locked->status === 'cancelled') {
                    return;
                }
                DB::table('orders')->where('id', $orderId)->update(['status' => 'cancelled', 'cancelled_at' => now()]);
                DB::update('UPDATE products SET stock = stock + ? WHERE id = ?', [(int) $locked->quantity, $locked->product_id]);
                DB::table('night_holds')->where('order_id', $orderId)->where('status', 'held')->update(['status' => 'cancelled']);
                app(LedgerService::class)->reverseOrder($orderId);
            });

            return;
        }
        if ($order->status === 'processing') {
            $payments->cancelByBuyer($user, $orderId, ignoreUndoWindow: true);

            return;
        }
        $payments->refund($orderId, (int) $order->total_minor - (int) $order->amount_refunded_minor, 'requested_by_customer', restock: true);
    }

    /**
     * Active listings a buyer may purchase: platform products, or a verified seller's stock.
     * Keyset by id. The route is authenticated and throttled.
     *
     * @return array{data: list<array<string, mixed>>, meta: array{next_cursor: string|null}}
     */
    public function catalog(?string $cursor, int $limit = 20): array
    {
        $limit = max(1, min(50, $limit));
        $rows = DB::table('products as p')
            ->leftJoin('sellers as s', 's.user_id', '=', 'p.seller_id')
            ->where('p.stock', '>', 0)
            ->where('p.listing_status', 'active')
            ->where(function ($query): void {
                $query->whereNull('p.seller_id')->orWhere('s.status', 'verified');
            })
            ->when($cursor !== null, fn ($query) => $query->where('p.id', '>', $cursor))
            ->orderBy('p.id')
            ->limit($limit + 1)
            ->get(['p.id', 'p.title', 'p.price_minor', 'p.currency', 'p.stock']);
        $page = $rows->take($limit)->values();
        $next = $rows->count() > $limit ? (string) $page->last()->id : null;

        return [
            'data' => $page->map(fn (object $product): array => [
                'id' => $product->id,
                'title' => $product->title,
                'price_minor' => (int) $product->price_minor,
                'currency' => $product->currency,
                'stock' => (int) $product->stock,
                'checkout_path' => '/reels',
            ])->all(),
            'meta' => ['next_cursor' => $next],
        ];
    }

    /** @return array<string, mixed> */
    public function card(string $kind, string $id): array
    {
        if ($kind === 'squad') {
            $squad = DB::table('squads')->where('id', $id)->first();
            if ($squad === null) {
                throw ApiException::squadClosed();
            }
            $members = (int) DB::table('squad_members')->where('squad_id', $id)->count();

            return ['kind' => 'squad', 'members' => $members, 'max_members' => (int) $squad->max_members, 'expires_at' => (string) $squad->expires_at];
        }
        if ($kind === 'auction') {
            $auction = DB::table('auctions')->where('id', $id)->first();
            if ($auction === null) {
                throw ApiException::giftClosed();
            }

            return ['kind' => 'auction', 'current_minor' => (int) $auction->current_minor, 'ends_at' => (string) $auction->ends_at];
        }
        $gift = DB::table('gifts')->where('token', $id)->first(['status', 'expires_at']);
        if ($gift === null) {
            throw ApiException::giftClosed();
        }

        return ['kind' => 'gift', 'status' => $gift->status, 'expires_at' => (string) $gift->expires_at];
    }

    /** @return array<string, mixed> */
    private function room(string $token, User $viewer): array
    {
        $room = DB::table('shop_rooms')->where('token', $token)->first();
        $members = (int) DB::table('shop_room_members')->where('room_id', $room->id)->count();
        $votes = DB::table('shop_room_votes')->where('room_id', $room->id)->selectRaw('choice, count(*) as votes')->groupBy('choice')->get();

        return [
            'token' => $room->token,
            'visibility' => $room->visibility,
            'ends_at' => (string) $room->ends_at,
            'members' => $members,
            'votes' => $votes,
            'url' => rtrim((string) config('app.url'), '/').'/rooms/'.$room->token,
            'viewer_in' => DB::table('shop_room_members')->where('room_id', $room->id)->where('user_id', $viewer->id)->exists(),
        ];
    }

    private function memberRoom(User $user, string $token): object
    {
        $room = DB::table('shop_rooms')->where('token', $token)->first();
        $in = $room !== null && DB::table('shop_room_members')->where('room_id', $room->id)->where('user_id', $user->id)->exists();
        if (! $in || now()->greaterThan($room->ends_at)) {
            throw ApiException::giftClosed();
        }

        return $room;
    }

    private function ensureShow(): void
    {
        if (DB::table('live_shows')->where('ends_at', '>', now())->exists()) {
            return;
        }
        $host = DB::table('users')->whereNull('deleted_at')->orderBy('created_at')->value('id');
        if ($host === null) {
            return;
        }
        DB::table('live_shows')->insert([
            'id' => (string) Str::uuid7(),
            'host_id' => $host,
            'title' => 'Sneakers on the hour',
            'category' => 'sneakers',
            'region' => 'Tbilisi',
            'starts_at' => now(),
            'ends_at' => now()->addMinutes(60),
            'created_at' => now(),
        ]);
    }

    private function awardTrend(User $user): void
    {
        $orders = DB::table('orders')->where('buyer_id', $user->id)->where('status', 'paid')->get(['product_id', 'created_at']);
        foreach ($orders as $order) {
            $early = DB::table('orders')->where('product_id', $order->product_id)->where('status', 'paid')->where('created_at', '<=', $order->created_at)->count();
            $total = DB::table('orders')->where('product_id', $order->product_id)->where('status', 'paid')->count();
            if ($early > 5 || $total < 5) {
                continue;
            }
            $inserted = DB::table('trend_awards')->insertOrIgnore([
                'user_id' => $user->id,
                'product_id' => $order->product_id,
                'points' => 20,
                'created_at' => now(),
            ]);
            if ($inserted === 1) {
                DB::table('users')->where('id', $user->id)->increment('trend_hunter_points', 20);
            }
        }
        $points = (int) DB::table('users')->where('id', $user->id)->value('trend_hunter_points');
        DB::table('users')->where('id', $user->id)->update(['trend_hunter_level' => min(5, intdiv($points, 20))]);
    }
}

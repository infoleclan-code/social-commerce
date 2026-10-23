<?php

declare(strict_types=1);

namespace App\Services\Commerce;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\Payments\AuthorizeRequest;
use App\Services\Payments\CardPaymentService;
use App\Services\Payments\PaymentGateway;
use App\Support\Currency;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Habit loops (check-in, missions, a once-a-day spin) and scale loops
 * (private gifts, try-on polls, closet resale, auctions, blind boxes).
 */
final class HabitScaleService
{
    /** Published spin odds. Weights sum to 10_000. */
    private const SPIN = [
        ['discount_bps' => 1000, 'weight' => 5000, 'label' => '10% off'],
        ['discount_bps' => 1500, 'weight' => 3000, 'label' => '15% off'],
        ['discount_bps' => 2000, 'weight' => 1500, 'label' => '20% off'],
        ['discount_bps' => 3000, 'weight' => 500, 'label' => '30% off'],
    ];

    public function __construct(
        private readonly Purchases $purchases,
        private readonly CardPaymentService $cards,
        private readonly PaymentGateway $gateway,
    ) {}

    /**
     * A gift is a real purchase: the buyer's card is authorized and captured like any order, and the
     * link becomes claimable only once the payment is captured (status paid). An unclaimed gift is
     * refunded when it expires (gifts:refund-expired).
     *
     * @return array<string, mixed>
     */
    public function createGift(User $buyer, string $productId, ?string $postId, string $idempotencyKey): array
    {
        $existing = $this->purchases->existing($buyer, $idempotencyKey);
        if ($existing !== null && ((string) $existing->product_id !== $productId || $existing->purpose !== 'gift')) {
            throw ApiException::idempotencyConflict();
        }
        [$order, $step, $replayed] = $this->purchases->place($buyer, $idempotencyKey, 'gift', function () use ($buyer, $productId, $postId): array {
            $product = DB::table('products')->where('id', $productId)->lockForUpdate()->first();
            if ($product === null || $product->listing_status !== 'active' || (int) $product->stock < 1) {
                throw ApiException::outOfStock();
            }
            if (DB::update('UPDATE products SET stock = stock - 1 WHERE id = ? AND stock >= 1', [$productId]) !== 1) {
                throw ApiException::outOfStock();
            }
            $charge = LandedCost::charge($product, $buyer->country_code, (int) $product->price_minor, 1);

            return [
                'product_id' => $productId,
                'post_id' => $postId,
                'quantity' => 1,
                'unit_price_minor' => (int) $product->price_minor,
                'discount_bps' => 0,
                'total_minor' => $charge['total_minor'],
                'currency' => $product->currency,
            ];
        }, function (object $order) use ($buyer, $productId, $postId): void {
            DB::table('gifts')->insert([
                'id' => (string) Str::uuid7(),
                'buyer_id' => $buyer->id,
                'product_id' => $productId,
                'post_id' => $postId,
                'order_id' => $order->id,
                'token' => Str::random(24),
                'status' => 'pending',
                'expires_at' => now()->addDays(7),
                'created_at' => now(),
            ]);
        });
        $gift = DB::table('gifts')->where('order_id', $order->id)->first();
        $product = DB::table('products')->where('id', $order->product_id)->first();

        return $this->giftForBuyer($gift, $product) + $step->toArray() + ['replayed' => $replayed];
    }

    /** @return array<string, mixed> */
    public function showGift(User $viewer, string $token): array
    {
        $gift = DB::table('gifts')->where('token', $token)->first();
        if ($gift === null) {
            throw ApiException::giftClosed();
        }
        $product = DB::table('products')->where('id', $gift->product_id)->first();
        $payload = [
            'token' => $gift->token,
            'status' => $gift->status,
            'expires_at' => (string) $gift->expires_at,
            'can_claim' => $gift->status === 'paid' && (string) $gift->buyer_id !== $viewer->id && now()->lessThan($gift->expires_at),
            'product' => [
                'id' => $product->id,
                'title' => $product->title,
                'image_url' => $product->image_url,
                'price' => $this->money((int) $product->price_minor, (string) $product->currency),
            ],
        ];
        if ((string) $gift->claimed_by === (string) $viewer->id) {
            $address = $this->openAddress($gift);
            if ($address !== null) {
                $payload['address'] = $address;
            }
        }

        return $payload;
    }

    /** @param  array{line: string, locality: string, postal_code: string, country: string}  $address */
    public function claimGift(User $recipient, string $token, array $address): array
    {
        return DB::transaction(function () use ($recipient, $token, $address): array {
            $gift = DB::table('gifts')->where('token', $token)->lockForUpdate()->first();
            if ($gift === null || (string) $gift->buyer_id === (string) $recipient->id || now()->greaterThan($gift->expires_at)) {
                throw ApiException::giftClosed();
            }
            if ($gift->status === 'claimed' && (string) $gift->claimed_by === (string) $recipient->id) {
                return $this->showGift($recipient, $token);
            }
            if ($gift->status !== 'paid') {
                throw ApiException::giftClosed();
            }
            DB::table('gifts')->where('id', $gift->id)->update([
                'status' => 'claimed',
                'claimed_by' => $recipient->id,
                'claimed_at' => now(),
                'address_line' => null,
                'locality' => null,
                'postal_code' => null,
                'country' => null,
                'address_ciphertext' => $this->sealAddress($address),
            ]);

            return $this->showGift($recipient, $token);
        });
    }

    /** @return array<string, mixed> */
    public function ritual(User $user): array
    {
        $today = now()->toDateString();
        $checkin = DB::table('ritual_checkins')->where('user_id', $user->id)->where('day', $today)->first();
        $missions = DB::table('ritual_missions')->where('user_id', $user->id)->where('day', $today)->pluck('kind')->all();
        $spin = DB::table('prize_spins')->where('user_id', $user->id)->where('day', $today)->first();

        return [
            'day' => $today,
            'streak' => $checkin === null ? $this->previousStreak($user) : (int) $checkin->streak,
            'checked_in' => $checkin !== null,
            'missions' => [
                ['kind' => 'watch', 'done' => in_array('watch', $missions, true)],
                ['kind' => 'like', 'done' => in_array('like', $missions, true)],
                ['kind' => 'share', 'done' => in_array('share', $missions, true)],
            ],
            'spin' => [
                'available' => $spin === null,
                'odds' => array_map(fn (array $row): array => [
                    'label' => $row['label'],
                    'weight_bps' => $row['weight'],
                    'percent' => $row['weight'] / 100,
                ], self::SPIN),
                'result' => $spin === null ? null : $this->spinResult($spin),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function checkIn(User $user): array
    {
        $optIn = (bool) DB::table('users')->where('id', $user->id)->value('streaks_opt_in');
        if (! $optIn) {
            throw ApiException::streaksOff();
        }
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();
        $existing = DB::table('ritual_checkins')->where('user_id', $user->id)->where('day', $today)->first();
        if ($existing === null) {
            $previous = DB::table('ritual_checkins')->where('user_id', $user->id)->where('day', $yesterday)->value('streak');
            DB::table('ritual_checkins')->insert([
                'user_id' => $user->id,
                'day' => $today,
                'streak' => ((int) $previous) + 1,
                'created_at' => now(),
            ]);
        }

        return $this->ritual($user);
    }

    /** @return array<string, mixed> */
    public function completeMission(User $user, string $kind): array
    {
        $today = now()->toDateString();
        $done = match ($kind) {
            'watch' => DB::table('reward_events')->where('user_id', $user->id)->where('kind', 'watch')->where('created_at', '>=', now()->startOfDay())->exists(),
            'like' => DB::table('feed_post_likes')->where('user_id', $user->id)->where('created_at', '>=', now()->startOfDay())->exists(),
            'share' => DB::table('referral_links')->where('user_id', $user->id)->where('created_at', '>=', now()->startOfDay())->exists(),
            default => false,
        };
        if (! $done) {
            throw ApiException::missionIncomplete();
        }
        DB::table('ritual_missions')->insertOrIgnore([
            'user_id' => $user->id,
            'day' => $today,
            'kind' => $kind,
            'created_at' => now(),
        ]);

        return $this->ritual($user);
    }

    /** @return array<string, mixed> */
    public function spin(User $user): array
    {
        $today = now()->toDateString();
        $existing = DB::table('prize_spins')->where('user_id', $user->id)->where('day', $today)->first();
        if ($existing !== null) {
            return ['replayed' => true, 'result' => $this->spinResult($existing), 'ritual' => $this->ritual($user)];
        }
        $roll = random_int(1, 10000);
        $cursor = 0;
        $picked = self::SPIN[0];
        foreach (self::SPIN as $row) {
            $cursor += $row['weight'];
            if ($roll <= $cursor) {
                $picked = $row;
                break;
            }
        }
        $couponId = (string) Str::uuid7();
        DB::table('coupons')->insert([
            'id' => $couponId,
            'user_id' => $user->id,
            'label' => ($picked['discount_bps'] / 100).'% off',
            'discount_bps' => $picked['discount_bps'],
            'expires_at' => now()->addDays(7),
            'created_at' => now(),
        ]);
        try {
            DB::table('prize_spins')->insert([
                'id' => (string) Str::uuid7(),
                'user_id' => $user->id,
                'day' => $today,
                'coupon_id' => $couponId,
                'discount_bps' => $picked['discount_bps'],
                'created_at' => now(),
            ]);
        } catch (QueryException) {
            DB::table('coupons')->where('id', $couponId)->delete();
            $existing = DB::table('prize_spins')->where('user_id', $user->id)->where('day', $today)->first();

            return ['replayed' => true, 'result' => $this->spinResult($existing), 'ritual' => $this->ritual($user)];
        }

        return [
            'replayed' => false,
            'result' => ['label' => $picked['label'], 'discount_bps' => $picked['discount_bps']],
            'ritual' => $this->ritual($user),
        ];
    }

    /** @return array<string, mixed> */
    public function renderTryOn(User $user, string $productId, ?string $photoBytes): array
    {
        $product = DB::table('products')->where('id', $productId)->first();
        if ($product === null) {
            throw ApiException::outOfStock();
        }
        $hash = null;
        if ($photoBytes !== null && $photoBytes !== '') {
            if (! $this->isImage($photoBytes)) {
                throw ApiException::imageRejected();
            }
            $hash = hash('sha256', $photoBytes);
        }
        $id = (string) Str::uuid7();
        DB::table('try_on_renders')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'product_id' => $productId,
            'photo_sha256' => $hash,
            'renderer' => 'composite_v1',
            'image_url' => $product->image_url,
            'created_at' => now(),
        ]);

        return [
            'id' => $id,
            'renderer' => 'composite_v1',
            'image_url' => $product->image_url,
            'photo_received' => $hash !== null,
            'product' => ['id' => $product->id, 'title' => $product->title],
        ];
    }

    /** @return array<string, mixed> */
    public function openPoll(User $user, string $renderId): array
    {
        $render = DB::table('try_on_renders')->where('id', $renderId)->where('user_id', $user->id)->first();
        if ($render === null) {
            throw ApiException::outOfStock();
        }
        $existing = DB::table('try_on_polls')->where('render_id', $renderId)->first();
        if ($existing === null) {
            $token = Str::random(16);
            DB::table('try_on_polls')->insert([
                'id' => (string) Str::uuid7(),
                'render_id' => $renderId,
                'host_id' => $user->id,
                'token' => $token,
                'created_at' => now(),
            ]);
        } else {
            $token = (string) $existing->token;
        }

        return $this->poll($token, $user);
    }

    /** @return array<string, mixed> */
    public function vote(User $user, string $token, string $choice): array
    {
        $poll = DB::table('try_on_polls')->where('token', $token)->first();
        if ($poll === null || (string) $poll->host_id === $user->id) {
            throw ApiException::giftClosed();
        }
        DB::table('try_on_votes')->insertOrIgnore([
            'poll_id' => $poll->id,
            'user_id' => $user->id,
            'choice' => $choice,
            'created_at' => now(),
        ]);

        return $this->poll($token, $user);
    }

    /** @return array<string, mixed> */
    public function poll(string $token, ?User $viewer): array
    {
        $poll = DB::table('try_on_polls')->where('token', $token)->first();
        if ($poll === null) {
            throw ApiException::giftClosed();
        }
        $render = DB::table('try_on_renders')->where('id', $poll->render_id)->first();
        $wear = (int) DB::table('try_on_votes')->where('poll_id', $poll->id)->where('choice', 'wear')->count();
        $skip = (int) DB::table('try_on_votes')->where('poll_id', $poll->id)->where('choice', 'skip')->count();

        return [
            'token' => $token,
            'image_url' => $render->image_url,
            'wear' => $wear,
            'skip' => $skip,
            'mine' => $viewer === null ? null : DB::table('try_on_votes')->where('poll_id', $poll->id)->where('user_id', $viewer->id)->value('choice'),
            'url' => rtrim((string) config('app.url'), '/').'/try-on/'.$token,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function closet(User $user): array
    {
        $missing = DB::table('orders')
            ->where('buyer_id', $user->id)
            ->where('status', 'paid')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('closet_items')->whereColumn('closet_items.order_id', 'orders.id');
            })
            ->get(['id', 'product_id', 'created_at']);
        foreach ($missing as $order) {
            DB::table('closet_items')->insertOrIgnore([
                'id' => (string) Str::uuid7(),
                'user_id' => $user->id,
                'order_id' => $order->id,
                'product_id' => $order->product_id,
                'status' => 'owned',
                'created_at' => $order->created_at,
            ]);
        }
        $rows = DB::table('closet_items as c')
            ->join('products as p', 'p.id', '=', 'c.product_id')
            ->join('orders as o', 'o.id', '=', 'c.order_id')
            ->where('c.user_id', $user->id)
            ->orderByDesc('c.created_at')
            ->get(['c.id', 'c.status', 'c.listing_price_minor', 'p.title', 'p.image_url', 'p.price_minor', 'p.currency', 'o.created_at']);

        return $rows->map(function (object $row): array {
            $estimate = $this->estimate((int) $row->price_minor, (string) $row->created_at);

            return [
                'id' => $row->id,
                'title' => $row->title,
                'image_url' => $row->image_url,
                'status' => $row->status,
                'estimate' => $this->money($estimate, (string) $row->currency),
                'listing_price' => $row->listing_price_minor === null ? null : $this->money((int) $row->listing_price_minor, (string) $row->currency),
            ];
        })->all();
    }

    /** @return array<string, mixed> */
    public function listCloset(User $user, string $itemId): array
    {
        $item = DB::table('closet_items as c')
            ->join('products as p', 'p.id', '=', 'c.product_id')
            ->join('orders as o', 'o.id', '=', 'c.order_id')
            ->where('c.id', $itemId)
            ->where('c.user_id', $user->id)
            ->first(['c.id', 'c.status', 'p.price_minor', 'p.currency', 'o.created_at']);
        if ($item === null || $item->status !== 'owned') {
            throw ApiException::outOfStock();
        }
        $estimate = $this->estimate((int) $item->price_minor, (string) $item->created_at);
        DB::table('closet_items')->where('id', $itemId)->update([
            'status' => 'listed',
            'listing_price_minor' => $estimate,
            'listed_at' => now(),
        ]);

        return ['id' => $itemId, 'status' => 'listed', 'listing_price' => $this->money($estimate, (string) $item->currency)];
    }

    /** @return array<string, mixed> */
    public function liveAuction(): array
    {
        $live = DB::table('auctions')->where('ends_at', '>', now())->orderBy('ends_at')->first();

        return ['auction' => $live === null ? null : $this->auctionShape($live)];
    }

    /**
     * A bid leads only after the saved card is authorized for that amount. The previous leader's hold
     * is cancelled. Losing the row lock cancels the new hold instead of charging a bid that did not win.
     *
     * @return array<string, mixed>
     */
    public function bid(User $user, string $auctionId, int $amount): array
    {
        if (! Str::isUuid($auctionId)) {
            throw ApiException::bidTooLow();
        }
        $preview = DB::table('auctions')->where('id', $auctionId)->first();
        if ($preview === null || now()->greaterThan($preview->ends_at) || $amount <= (int) $preview->current_minor) {
            throw ApiException::bidTooLow();
        }
        $product = DB::table('products')->where('id', $preview->product_id)->first();
        if ($product === null) {
            throw ApiException::bidTooLow();
        }
        $method = $this->cards->defaultMethodId($user);
        $customer = $this->cards->customer($user);
        $intent = $this->gateway->authorize(new AuthorizeRequest(
            $customer,
            $method,
            $amount,
            (string) $product->currency,
            'bid-'.Str::lower(Str::random(20)),
            ['auction_id' => $auctionId, 'bidder_id' => (string) $user->id],
        ));
        if (! $intent->isAuthorized() || $intent->id === null) {
            if ($intent->id !== null) {
                $this->releaseHold($intent->id);
            }
            throw ApiException::cardDeclined();
        }
        $intentId = $intent->id;

        try {
            return DB::transaction(function () use ($user, $auctionId, $amount, $intentId): array {
                $auction = DB::table('auctions')->where('id', $auctionId)->lockForUpdate()->first();
                if ($auction === null || now()->greaterThan($auction->ends_at) || $amount <= (int) $auction->current_minor) {
                    $this->releaseHold($intentId);
                    throw ApiException::bidTooLow();
                }
                $previous = is_string($auction->leader_payment_intent_id) ? $auction->leader_payment_intent_id : null;
                DB::table('auction_bids')->insert([
                    'id' => (string) Str::uuid7(),
                    'auction_id' => $auctionId,
                    'bidder_id' => $user->id,
                    'amount_minor' => $amount,
                    'payment_intent_id' => $intentId,
                    'created_at' => now(),
                ]);
                DB::table('auctions')->where('id', $auctionId)->update([
                    'current_minor' => $amount,
                    'leader_id' => $user->id,
                    'leader_payment_intent_id' => $intentId,
                ]);
                if ($previous !== null && $previous !== '' && $previous !== $intentId) {
                    $this->releaseHold($previous);
                }

                return $this->auctionShape(DB::table('auctions')->where('id', $auctionId)->first());
            });
        } catch (ApiException $refused) {
            throw $refused;
        } catch (Throwable $failed) {
            $this->releaseHold($intentId);
            throw $failed;
        }
    }

    /** @return array<string, mixed> */
    public function blindBox(): array
    {
        $box = DB::table('blind_boxes')->orderBy('title')->first();
        if ($box === null) {
            return ['box' => null];
        }
        $odds = DB::table('blind_box_odds as o')
            ->join('products as p', 'p.id', '=', 'o.product_id')
            ->where('o.box_id', $box->id)
            ->orderByDesc('o.weight_bps')
            ->get(['p.title', 'o.weight_bps']);

        return [
            'box' => [
                'id' => $box->id,
                'title' => $box->title,
                'price' => $this->money((int) $box->price_minor, (string) $box->currency),
                'odds' => $odds->map(fn (object $row): array => [
                    'label' => $row->title,
                    'weight_bps' => (int) $row->weight_bps,
                    'percent' => ((int) $row->weight_bps) / 100,
                ])->all(),
            ],
        ];
    }

    /**
     * One paid pull per day. The roll is drawn when the order is placed (published odds, CSPRNG) but the
     * product is revealed only after the payment is captured, so abandoning a 3-D Secure step cannot be
     * used to re-roll. A pull whose payment fails frees the day.
     *
     * @return array<string, mixed>
     */
    public function pullBox(User $user, string $boxId, string $idempotencyKey): array
    {
        if (DB::table('users')->where('id', $user->id)->value('age_assured_at') === null) {
            throw ApiException::ageAssuranceRequired();
        }
        $today = now()->toDateString();
        $existing = $this->purchases->existing($user, $idempotencyKey);
        if ($existing === null) {
            $pulled = DB::table('blind_pulls')->where('user_id', $user->id)->where('pulled_on', $today)->where('status', '<>', 'failed')->first();
            if ($pulled !== null) {
                return $this->pullPayload($pulled, true);
            }
        }
        [$order, $step, $replayed] = $this->purchases->place($user, $idempotencyKey, 'blind_box', function () use ($user, $boxId, $today): array {
            if (DB::table('blind_pulls')->where('user_id', $user->id)->where('pulled_on', $today)->where('status', '<>', 'failed')->exists()) {
                throw ApiException::blindBoxPulled();
            }
            $box = Str::isUuid($boxId) ? DB::table('blind_boxes')->where('id', $boxId)->first() : null;
            $odds = $box === null ? collect() : DB::table('blind_box_odds')->where('box_id', $boxId)->orderBy('product_id')->get();
            $weights = (int) $odds->sum('weight_bps');
            if ($box === null || $odds->isEmpty() || $weights < 1) {
                throw ApiException::outOfStock();
            }
            $roll = random_int(1, $weights);
            $cursor = 0;
            $productId = (string) $odds->last()->product_id;
            foreach ($odds as $row) {
                $cursor += (int) $row->weight_bps;
                if ($roll <= $cursor) {
                    $productId = (string) $row->product_id;
                    break;
                }
            }

            return [
                'product_id' => $productId,
                'quantity' => 1,
                'unit_price_minor' => (int) $box->price_minor,
                'discount_bps' => 0,
                'total_minor' => (int) $box->price_minor,
                'currency' => $box->currency,
            ];
        }, function (object $order) use ($user, $boxId, $today): void {
            DB::table('blind_pulls')->insert([
                'id' => (string) Str::uuid7(),
                'box_id' => $boxId,
                'user_id' => $user->id,
                'product_id' => $order->product_id,
                'pulled_on' => $today,
                'order_id' => $order->id,
                'status' => 'pending',
                'created_at' => now(),
            ]);
        }, captureDelaySeconds: 0);

        $pull = DB::table('blind_pulls')->where('order_id', $order->id)->first();

        return $this->pullPayload($pull, $replayed) + $step->toArray();
    }

    /** @return array<string, mixed> */
    private function pullPayload(object $pull, bool $replayed): array
    {
        $product = $pull->status === 'paid' ? DB::table('products')->where('id', $pull->product_id)->first() : null;

        return [
            'replayed' => $replayed,
            'status' => $pull->status,
            'order_id' => $pull->order_id,
            'product' => $product === null ? null : ['id' => $product->id, 'title' => $product->title, 'image_url' => $product->image_url],
        ];
    }

    /** @return array<string, mixed> */
    private function giftForBuyer(object $gift, object $product): array
    {
        $base = rtrim((string) config('app.url'), '/');

        return [
            'token' => $gift->token,
            'url' => "{$base}/gift/{$gift->token}",
            // The buyer's own order: the app finishes 3-D Secure and syncs it (POST /orders/{id}/sync).
            'order_id' => $gift->order_id,
            'status' => $gift->status,
            'expires_at' => (string) $gift->expires_at,
            'product' => ['id' => $product->id, 'title' => $product->title],
        ];
    }

    private function previousStreak(User $user): int
    {
        return (int) DB::table('ritual_checkins')->where('user_id', $user->id)->where('day', now()->subDay()->toDateString())->value('streak');
    }

    /** @return array{label: string, discount_bps: int} */
    private function spinResult(object $spin): array
    {
        return [
            'label' => ((int) $spin->discount_bps / 100).'% off',
            'discount_bps' => (int) $spin->discount_bps,
        ];
    }

    private function estimate(int $priceMinor, string $purchasedAt): int
    {
        $age = now()->diffInDays($purchasedAt);
        $bps = $age < 30 ? 6000 : 4500;

        return intdiv($priceMinor * $bps, 10000);
    }

    /** @return array<string, mixed> */
    private function auctionShape(object $auction): array
    {
        $product = DB::table('products')->where('id', $auction->product_id)->first();
        $leader = $auction->leader_id === null ? null : DB::table('users')->where('id', $auction->leader_id)->value('username');

        return [
            'id' => $auction->id,
            'title' => $product->title ?? '',
            'image_url' => $product->image_url ?? null,
            'current' => $this->money((int) $auction->current_minor, (string) ($product->currency ?? 'USD')),
            'next_minor' => (int) $auction->current_minor + 100,
            'leader' => $leader,
            'ends_at' => (string) $auction->ends_at,
        ];
    }

    /** @param  array{line: string, locality: string, postal_code: string, country: string}  $address */
    private function sealAddress(array $address): string
    {
        return Crypt::encryptString(json_encode([
            'line' => $address['line'],
            'locality' => $address['locality'],
            'postal_code' => $address['postal_code'],
            'country' => strtoupper($address['country']),
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array{line: string, locality: string, postal_code: string, country: string}|null */
    private function openAddress(object $gift): ?array
    {
        if (is_string($gift->address_ciphertext ?? null) && $gift->address_ciphertext !== '') {
            try {
                $decoded = json_decode(Crypt::decryptString($gift->address_ciphertext), true, 8, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                return null;
            }
            if (! is_array($decoded)) {
                return null;
            }

            return [
                'line' => (string) ($decoded['line'] ?? ''),
                'locality' => (string) ($decoded['locality'] ?? ''),
                'postal_code' => (string) ($decoded['postal_code'] ?? ''),
                'country' => (string) ($decoded['country'] ?? ''),
            ];
        }
        if ($gift->address_line === null) {
            return null;
        }

        return [
            'line' => (string) $gift->address_line,
            'locality' => (string) $gift->locality,
            'postal_code' => (string) $gift->postal_code,
            'country' => (string) $gift->country,
        ];
    }

    private function releaseHold(string $paymentIntentId): void
    {
        try {
            $this->gateway->cancel($paymentIntentId, 'auction-release:'.$paymentIntentId);
        } catch (Throwable) {
            // The bid row is the source of truth; a stuck hold is released by the next outbid or expiry.
        }
    }

    /** @return array{amount_minor: int, currency: string, minor_unit: int} */
    private function money(int $minor, string $currency): array
    {
        return Currency::money($minor, $currency);
    }

    private function isImage(string $bytes): bool
    {
        if (strlen($bytes) < 12 || strlen($bytes) > 8_000_000) {
            return false;
        }

        return str_starts_with($bytes, "\xFF\xD8\xFF")
            || str_starts_with($bytes, "\x89PNG")
            || (str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP');
    }
}

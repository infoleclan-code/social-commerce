<?php

declare(strict_types=1);

namespace App\Services\Commerce;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\Payments\CardPaymentService;
use App\Services\Payments\OrderPayments;
use App\Services\Payments\PaymentStep;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The shared skeleton of every purchase (checkout, one-tap, squads, gifts, blind boxes).
 *
 *  1. replay: the same (buyer, Idempotency-Key) returns the order already created, never a second one;
 *  2. the buyer's saved card is required before any stock is reserved;
 *  3. one transaction, under a per-buyer advisory lock (spend limits and the night-hold burst count
 *     cannot be raced by parallel taps): the caller prices and reserves, then the order and its
 *     authorization row are inserted;
 *  4. Stripe is asked to authorize outside the transaction (OrderPayments).
 * A concurrent duplicate that loses the unique (buyer_id, idempotency_key) race replays the winner.
 */
final class Purchases
{
    public function __construct(
        private readonly OrderPayments $payments,
        private readonly CardPaymentService $cards,
    ) {}

    public function existing(User $buyer, string $idempotencyKey): ?object
    {
        return DB::table('orders')->where('buyer_id', $buyer->id)->where('idempotency_key', $idempotencyKey)->first();
    }

    /**
     * Runs $reserve inside the purchase transaction; it must return the order values (without id,
     * buyer, key, status) and may throw to abort. Returns [order row, payment step, replayed].
     *
     * @param  Closure(): array<string, mixed>  $reserve
     * @param  Closure(object): void|null  $after  extra rows written in the same transaction
     * @return array{0: object, 1: PaymentStep, 2: bool}
     */
    public function place(User $buyer, string $idempotencyKey, string $purpose, Closure $reserve, ?Closure $after = null, ?int $captureDelaySeconds = null): array
    {
        $existing = $this->existing($buyer, $idempotencyKey);
        if ($existing !== null) {
            return [$existing, $this->payments->step((string) $existing->id), true];
        }
        $paymentMethodId = $this->cards->defaultMethodId($buyer);

        try {
            [$orderId, $created] = DB::transaction(function () use ($buyer, $idempotencyKey, $purpose, $reserve, $after, $paymentMethodId, $captureDelaySeconds): array {
                DB::selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['purchase:'.$buyer->id]);
                $raced = $this->existing($buyer, $idempotencyKey);
                if ($raced !== null) {
                    return [(string) $raced->id, false];
                }
                $values = $reserve();
                $authorizeMinor = isset($values['authorize_minor']) ? (int) $values['authorize_minor'] : null;
                unset($values['authorize_minor']);
                $total = (int) $values['total_minor'];
                if ($total < 1 || $total > (int) config('commerce.max_order_minor')) {
                    throw ApiException::orderLimit();
                }
                $captureAfter = $this->guard($buyer, $total, $captureDelaySeconds);
                $orderId = (string) Str::uuid7();
                DB::table('orders')->insert($values + [
                    'id' => $orderId,
                    'buyer_id' => $buyer->id,
                    'status' => 'processing',
                    'payment_status' => 'pending',
                    'purpose' => $purpose,
                    'idempotency_key' => $idempotencyKey,
                    'created_at' => now(),
                ]);
                $order = DB::table('orders')->where('id', $orderId)->first();
                if ($captureAfter['held']) {
                    DB::table('night_holds')->insert([
                        'id' => (string) Str::uuid7(),
                        'user_id' => $buyer->id,
                        'order_id' => $orderId,
                        'status' => 'held',
                        'created_at' => now(),
                    ]);
                }
                if ($after !== null) {
                    $after($order);
                }
                $this->payments->open($order, $paymentMethodId, $captureAfter['at'], $authorizeMinor);

                return [$orderId, true];
            });
        } catch (QueryException $exception) {
            $winner = ($exception->errorInfo[0] ?? '') === '23505' ? $this->existing($buyer, $idempotencyKey) : null;
            if ($winner === null) {
                throw $exception;
            }

            return [$winner, $this->payments->step((string) $winner->id), true];
        }

        if (! $created) {
            return [DB::table('orders')->where('id', $orderId)->first(), $this->payments->step($orderId), true];
        }
        $step = $this->payments->authorize($orderId);

        return [DB::table('orders')->where('id', $orderId)->first(), $step, false];
    }

    /**
     * Account pause, monthly spend limit, and the late-night hold. Returns when the authorization may be
     * captured: after the undo window, or at 06:00 local time for a held late-night burst.
     *
     * @return array{at: CarbonImmutable, held: bool}
     */
    private function guard(User $buyer, int $total, ?int $captureDelaySeconds): array
    {
        $row = DB::table('users')->where('id', $buyer->id)->first(['paused_at', 'deletion_requested_at', 'timezone']);
        if ($row !== null && ($row->paused_at !== null || $row->deletion_requested_at !== null)) {
            throw ApiException::accountPaused();
        }
        $limit = DB::table('spend_limits')->where('user_id', $buyer->id)->first();
        if ($limit !== null && $limit->monthly_limit_minor !== null) {
            $spent = (int) DB::table('orders')
                ->where('buyer_id', $buyer->id)
                ->where('status', '!=', 'cancelled')
                ->where('created_at', '>=', now()->startOfMonth())
                ->sum(DB::raw('total_minor - amount_refunded_minor'));
            if ($spent + $total > (int) $limit->monthly_limit_minor) {
                throw ApiException::spendLimit();
            }
        }
        $now = CarbonImmutable::now();
        $delay = $captureDelaySeconds ?? (int) config('commerce.undo_seconds');
        $nightHold = $limit === null || (bool) $limit->night_hold;
        $timezone = $this->timezone($row->timezone ?? null);
        $local = $now->setTimezone($timezone);
        if ($nightHold && (int) $local->format('G') < 6) {
            $burst = (int) DB::table('orders')
                ->where('buyer_id', $buyer->id)
                ->where('status', '!=', 'cancelled')
                ->where('created_at', '>=', now()->subHours(2))
                ->count();
            if ($burst >= 2) {
                $morning = $local->setTime(6, 0)->utc();
                $later = $now->addSeconds(max(0, $delay));

                return ['at' => $later->greaterThan($morning) ? $later : $morning, 'held' => true];
            }
        }

        return ['at' => $now->addSeconds(max(0, $delay)), 'held' => false];
    }

    private function timezone(?string $name): string
    {
        return $name !== null && in_array($name, timezone_identifiers_list(), true) ? $name : 'UTC';
    }
}

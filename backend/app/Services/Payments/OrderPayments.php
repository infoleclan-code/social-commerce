<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\Commerce\LedgerService;
use App\Services\Commerce\OrderEffects;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The only place an order's payment changes state.
 *
 * Every purchase path follows the same three steps:
 *  1. inside the caller's transaction: the order (status processing, payment_status pending), its stock
 *     and the authorization row are written together (open());
 *  2. outside any transaction: Stripe is asked to authorize, with the idempotency key
 *     order:{id}:authorize (authorize()). A timeout leaves the payment pending; the reconciler re-sends
 *     the same request, which Stripe either replays or performs once;
 *  3. in a new transaction, holding the order and authorization row locks, the PaymentIntent state is
 *     applied (apply()). Transitions only move forward, so the authorize response, the capture job, a
 *     buyer's cancel and any number of webhooks can arrive in any order, any number of times.
 *
 * Capture happens after the undo window (or the squad/night-hold deadline) in captureDue(). Side
 * effects (commissions, price protection, gift/blind-box state) run in OrderEffects at the moment of
 * capture or release, inside the transition's transaction.
 *
 * Amounts, currency and order ids reported by Stripe are checked against our rows; anything that does
 * not match is recorded in payment_anomalies and not applied.
 */
final class OrderPayments
{
    /** After this many attempts with an unknown outcome, a pending authorization is given up. */
    public const MAX_AUTHORIZE_ATTEMPTS = 6;

    /** A 3-D Secure step not finished within this many minutes is canceled. */
    public const ACTION_TIMEOUT_MINUTES = 30;

    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly CardPaymentService $cards,
        private readonly OrderEffects $effects,
        private readonly LedgerService $ledger,
    ) {}

    // ---------------------------------------------------------------- 1. open

    /** Inside the caller's transaction, right after the order row was inserted. */
    public function open(object $order, string $paymentMethodId, DateTimeInterface $captureAfter, ?int $authorizeMinor = null): void
    {
        DB::table('payment_authorizations')->insert([
            'id' => (string) Str::uuid7(),
            'buyer_id' => $order->buyer_id,
            'product_id' => $order->product_id,
            'order_id' => $order->id,
            'squad_id' => $order->squad_id,
            'purpose' => $order->purpose,
            'status' => 'pending',
            'authorized_minor' => $authorizeMinor ?? (int) $order->total_minor,
            'currency' => $order->currency,
            'idempotency_key' => $order->idempotency_key,
            'stripe_payment_method_id' => $paymentMethodId,
            'expires_at' => $captureAfter,
            'capture_after' => $captureAfter,
            'created_at' => now(),
        ]);
    }

    // ---------------------------------------------------------------- 2. authorize

    public function authorize(string $orderId): PaymentStep
    {
        $order = DB::table('orders')->where('id', $orderId)->first();
        $auth = $this->authorization($orderId);
        if ($order === null || $auth === null) {
            throw ApiException::orderNotFound();
        }
        if ($auth->status !== 'pending') {
            return $this->step($orderId);
        }
        $buyer = User::query()->findOrFail($order->buyer_id);
        try {
            $intent = $this->gateway->authorize(new AuthorizeRequest(
                customerId: $this->cards->customer($buyer),
                paymentMethodId: (string) $auth->stripe_payment_method_id,
                amountMinor: (int) $auth->authorized_minor,
                currency: (string) $order->currency,
                idempotencyKey: 'order:'.$orderId.':authorize',
                metadata: [
                    'order_id' => $orderId,
                    'buyer_id' => (string) $order->buyer_id,
                    'purpose' => (string) $order->purpose,
                ],
            ));
        } catch (PaymentOutcomeUnknown $unknown) {
            $this->deferAttempt($auth->id, 'outcome_unknown');
            Log::warning('payments.authorize.unknown', ['order_id' => $orderId]);

            return new PaymentStep('pending');
        } catch (PaymentGatewayUnavailable|ApiException $unavailable) {
            // Stripe did not act: release the order now so stock is not held by a payment that never started.
            return $this->releaseLocally($orderId, 'failed', 'payments_unavailable');
        }

        $step = $this->apply($orderId, $intent, 'authorize');
        if ($step->paymentStatus === 'authorized') {
            $fresh = $this->authorization($orderId);
            if ($fresh !== null && $fresh->capture_after !== null && now()->greaterThanOrEqualTo($fresh->capture_after)) {
                $this->capture($orderId);

                return $this->step($orderId);
            }
        }

        return $step;
    }

    // ---------------------------------------------------------------- 3. apply

    /** Applies a PaymentIntent state reported by Stripe (a response, a retrieve, or a webhook). */
    public function apply(string $orderId, PaymentIntentResult $intent, string $source, ?string $eventId = null): PaymentStep
    {
        $compensation = null;
        $step = DB::transaction(function () use ($orderId, $intent, $source, $eventId, &$compensation): PaymentStep {
            $order = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
            $auth = $order === null ? null : DB::table('payment_authorizations')->where('order_id', $orderId)->orderByDesc('created_at')->lockForUpdate()->first();
            if ($order === null || $auth === null) {
                $this->anomaly(null, $eventId, 'unknown_intent', ['source' => $source, 'payment_intent' => $intent->id]);

                return new PaymentStep('unknown');
            }
            $mismatch = $this->mismatch($order, $auth, $intent);
            if ($mismatch !== null) {
                $this->anomaly($orderId, $eventId, $mismatch, [
                    'source' => $source,
                    'payment_intent' => $intent->id,
                    'amount' => $intent->amount,
                    'currency' => $intent->currency,
                ]);

                return new PaymentStep((string) $order->payment_status);
            }
            if ($auth->stripe_payment_intent_id === null && $intent->id !== null) {
                DB::table('payment_authorizations')->where('id', $auth->id)->update(['stripe_payment_intent_id' => $intent->id]);
                DB::table('orders')->where('id', $orderId)->update(['stripe_payment_intent_id' => $intent->id]);
                $auth->stripe_payment_intent_id = $intent->id;
                $order->stripe_payment_intent_id = $intent->id;
            }

            $current = (string) $auth->status;
            $closed = in_array($current, ['voided', 'failed'], true);
            if ($intent->isCaptured()) {
                if ($closed) {
                    $this->anomaly($orderId, $eventId, 'late_capture', ['source' => $source, 'amount_received' => $intent->amountReceived]);
                    $compensation = 'refund';
                } elseif ($current !== 'captured') {
                    $this->markCaptured($order, $auth, $intent->amountReceived);
                }
            } elseif ($intent->isAuthorized()) {
                if (in_array($current, ['pending', 'requires_action'], true)) {
                    $this->transition($order, $auth, 'authorized', 'authorized');
                } elseif ($closed) {
                    $compensation = 'cancel';
                }
            } elseif ($intent->needsAction()) {
                if ($current === 'pending') {
                    $this->transition($order, $auth, 'requires_action', 'requires_action');
                }
            } elseif ($intent->isFailed()) {
                if (in_array($current, ['pending', 'requires_action', 'authorized'], true)) {
                    $this->release($order, $auth, 'failed', $intent->failureCode ?? 'card_declined');
                }
            } elseif ($intent->isCanceled()) {
                if (in_array($current, ['pending', 'requires_action', 'authorized'], true)) {
                    $this->release($order, $auth, 'voided', 'canceled');
                }
            }

            $fresh = DB::table('orders')->where('id', $orderId)->first(['payment_status']);
            $failure = DB::table('payment_authorizations')->where('id', $auth->id)->value('last_error');
            $nextAction = $fresh->payment_status === 'requires_action' && $intent->clientSecret !== null
                ? ['type' => 'use_stripe_sdk', 'client_secret' => $intent->clientSecret]
                : null;

            return new PaymentStep((string) $fresh->payment_status, $nextAction, in_array($fresh->payment_status, ['failed', 'voided'], true) ? $failure : null);
        });

        if ($compensation !== null && $intent->id !== null) {
            $this->compensate($orderId, $intent, $compensation);
        }

        return $step;
    }

    // ---------------------------------------------------------------- capture

    public function capture(string $orderId): void
    {
        $order = DB::table('orders')->where('id', $orderId)->first();
        $auth = $this->authorization($orderId);
        if ($order === null || $auth === null || $auth->status !== 'authorized' || $auth->stripe_payment_intent_id === null) {
            return;
        }
        $amount = min((int) $order->total_minor, (int) $auth->authorized_minor);
        try {
            $intent = $this->gateway->capture((string) $auth->stripe_payment_intent_id, $amount, (string) $order->currency, 'order:'.$orderId.':capture:'.$amount);
        } catch (PaymentOutcomeUnknown|PaymentGatewayUnavailable $retry) {
            $this->deferAttempt($auth->id, 'capture_retry');

            return;
        } catch (PaymentRejected $rejected) {
            $this->deferAttempt($auth->id, 'capture_rejected:'.$rejected->stripeCode);
            $this->anomaly($orderId, null, 'capture_failed', ['code' => $rejected->stripeCode]);

            return;
        }
        $this->apply($orderId, $intent, 'capture');
    }

    /** Captures authorizations whose undo window (or squad / night-hold deadline) has passed. */
    public function captureDue(int $limit = 100): int
    {
        $orders = DB::transaction(fn () => DB::select(
            "UPDATE payment_authorizations
                SET next_attempt_at = clock_timestamp() + interval '2 minutes', attempts = attempts + 1
              WHERE id IN (
                    SELECT id FROM payment_authorizations
                     WHERE status = 'authorized'
                       AND capture_after <= clock_timestamp()
                       AND (next_attempt_at IS NULL OR next_attempt_at <= clock_timestamp())
                     ORDER BY capture_after
                     LIMIT ?
                     FOR UPDATE SKIP LOCKED)
            RETURNING order_id",
            [$limit],
        ));
        foreach ($orders as $row) {
            $this->guarded(fn () => $this->capture((string) $row->order_id), 'capture', (string) $row->order_id);
        }

        return count($orders);
    }

    // ---------------------------------------------------------------- buyer actions

    /**
     * Undo inside the window: voids the hold. A withdrawal (ignoreUndoWindow) may void any hold that has
     * not been captured yet. After capture, use refund().
     */
    public function cancelByBuyer(User $buyer, string $orderId, bool $ignoreUndoWindow = false): void
    {
        $order = DB::table('orders')->where('id', $orderId)->where('buyer_id', $buyer->id)->first();
        if ($order === null) {
            throw ApiException::orderNotFound();
        }
        $auth = $this->authorization($orderId);
        if ($order->status !== 'processing' || $auth === null || ! in_array($auth->status, ['pending', 'requires_action', 'authorized'], true)) {
            throw ApiException::notCancellable();
        }
        if (! $ignoreUndoWindow && $auth->status === 'authorized' && $auth->capture_after !== null && now()->greaterThanOrEqualTo($auth->capture_after)) {
            throw ApiException::notCancellable();
        }
        if ($auth->stripe_payment_intent_id === null) {
            // Stripe never answered. Close it here; apply() cancels any hold that shows up later.
            $this->releaseLocally($orderId, 'voided', 'buyer_cancelled');

            return;
        }
        try {
            $intent = $this->gateway->cancel((string) $auth->stripe_payment_intent_id, 'order:'.$orderId.':cancel');
        } catch (PaymentOutcomeUnknown|PaymentGatewayUnavailable|PaymentRejected) {
            throw ApiException::paymentsUnavailable();
        }
        $this->apply($orderId, $intent, 'cancel');
        if ($intent->isCaptured()) {
            throw ApiException::notCancellable();
        }
    }

    /**
     * Refunds a captured order. The idempotency key carries the cumulative refunded amount, so a retry
     * after a timeout (or two concurrent requests) refunds once.
     */
    public function refund(string $orderId, int $amountMinor, string $reason, bool $restock): void
    {
        $order = DB::table('orders')->where('id', $orderId)->first();
        if ($order === null || $order->stripe_payment_intent_id === null) {
            throw ApiException::orderNotFound();
        }
        if (! in_array($order->payment_status, ['captured', 'partially_refunded', 'disputed', 'refunded'], true)) {
            throw ApiException::refundRejected();
        }
        $remaining = (int) $order->total_minor - (int) $order->amount_refunded_minor;
        $amount = min($amountMinor, $remaining);
        if ($amount < 1) {
            return;
        }
        $cumulative = (int) $order->amount_refunded_minor + $amount;
        try {
            $this->gateway->refund((string) $order->stripe_payment_intent_id, $amount, (string) $order->currency, 'order:'.$orderId.':refund:'.$cumulative, $reason);
        } catch (PaymentOutcomeUnknown|PaymentGatewayUnavailable) {
            throw ApiException::paymentsUnavailable();
        } catch (PaymentRejected $rejected) {
            throw ApiException::refundRejected();
        }
        $this->applyRefund($orderId, $cumulative, $restock);
    }

    /** Applies a cumulative refunded amount (from our refund call or a charge.refunded webhook). */
    public function applyRefund(string $orderId, int $cumulativeRefundedMinor, bool $restock): void
    {
        DB::transaction(function () use ($orderId, $cumulativeRefundedMinor, $restock): void {
            $order = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
            if ($order === null || ! in_array($order->payment_status, ['captured', 'partially_refunded', 'disputed', 'refunded'], true)) {
                return;
            }
            $total = (int) $order->total_minor;
            $refunded = min($total, max((int) $order->amount_refunded_minor, $cumulativeRefundedMinor));
            if ($refunded > (int) $order->amount_refunded_minor) {
                $full = $refunded >= $total;
                $values = [
                    'amount_refunded_minor' => $refunded,
                    'payment_status' => $full ? 'refunded' : ($order->payment_status === 'disputed' ? 'disputed' : 'partially_refunded'),
                ];
                if ($full && $order->status === 'paid') {
                    $values += ['status' => 'cancelled', 'cancelled_at' => now()];
                }
                DB::table('orders')->where('id', $orderId)->update($values);
                if ($full && $order->status === 'paid') {
                    $this->effects->onRefundedFully($order, $restock);
                }
            }
            $this->ledger->recordRefund($orderId, $refunded, (string) $order->currency);
            $this->ledger->settleCommissions($orderId, $refunded, $total);
            $this->ledger->settlePriceProtection($orderId, $refunded, $total);
            $this->ledger->settlePlatformFee($orderId, $refunded, $total);
        });
    }

    /**
     * A dispute opened, won, or lost. Disputes move open -> won|lost only; a lost dispute is treated as
     * a full refund (commissions and price protection reversed), without restocking.
     */
    public function applyDispute(string $orderId, string $disputeId, string $status, int $amountMinor, string $currency, ?string $reason): void
    {
        $status = match ($status) {
            'won' => 'won',
            'lost' => 'lost',
            default => 'open',
        };
        $lost = DB::transaction(function () use ($orderId, $disputeId, $status, $amountMinor, $currency, $reason): bool {
            $order = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
            if ($order === null) {
                return false;
            }
            $existing = DB::table('payment_disputes')->where('id', $disputeId)->lockForUpdate()->first();
            if ($existing === null) {
                DB::table('payment_disputes')->insert([
                    'id' => $disputeId,
                    'order_id' => $orderId,
                    'status' => $status,
                    'amount_minor' => $amountMinor,
                    'currency' => strtoupper($currency),
                    'reason' => $reason === null ? null : mb_substr($reason, 0, 60),
                    'opened_at' => now(),
                    'closed_at' => $status === 'open' ? null : now(),
                ]);
            } elseif ($existing->status === 'open' && $status !== 'open') {
                DB::table('payment_disputes')->where('id', $disputeId)->update(['status' => $status, 'closed_at' => now()]);
            } else {
                return false;
            }

            if ($status === 'open' && in_array($order->payment_status, ['captured', 'partially_refunded'], true)) {
                DB::table('orders')->where('id', $orderId)->update(['payment_status' => 'disputed']);
            } elseif ($status === 'won' && $order->payment_status === 'disputed') {
                DB::table('orders')->where('id', $orderId)->update([
                    'payment_status' => (int) $order->amount_refunded_minor > 0 ? 'partially_refunded' : 'captured',
                ]);
            }

            return $status === 'lost';
        });
        if ($lost) {
            $total = (int) DB::table('orders')->where('id', $orderId)->value('total_minor');
            $this->applyRefund($orderId, $total, false);
        }
    }

    /** Asks Stripe for the current state (used after the app finishes 3-D Secure, and by the reconciler). */
    public function sync(string $orderId): PaymentStep
    {
        $auth = $this->authorization($orderId);
        if ($auth === null) {
            throw ApiException::orderNotFound();
        }
        if ($auth->stripe_payment_intent_id === null) {
            return $auth->status === 'pending' ? $this->authorize($orderId) : $this->step($orderId);
        }
        try {
            $intent = $this->gateway->retrieve((string) $auth->stripe_payment_intent_id);
        } catch (PaymentOutcomeUnknown|PaymentGatewayUnavailable|PaymentRejected) {
            return $this->step($orderId);
        }

        return $this->apply($orderId, $intent, 'sync');
    }

    /**
     * Resolves payments stuck in an undecided state:
     *  - pending with no answer: re-send the same authorization (Stripe replays or performs it once);
     *    after MAX_AUTHORIZE_ATTEMPTS the order is released;
     *  - requires_action older than ACTION_TIMEOUT_MINUTES: the buyer abandoned 3-D Secure; cancel.
     */
    public function reconcile(int $limit = 100): int
    {
        $pending = DB::transaction(fn () => DB::select(
            "UPDATE payment_authorizations
                SET next_attempt_at = clock_timestamp() + interval '1 minute'
              WHERE id IN (
                    SELECT id FROM payment_authorizations
                     WHERE status = 'pending'
                       AND created_at <= clock_timestamp() - interval '20 seconds'
                       AND (next_attempt_at IS NULL OR next_attempt_at <= clock_timestamp())
                     ORDER BY created_at
                     LIMIT ?
                     FOR UPDATE SKIP LOCKED)
            RETURNING order_id, attempts",
            [$limit],
        ));
        foreach ($pending as $row) {
            if ((int) $row->attempts >= self::MAX_AUTHORIZE_ATTEMPTS) {
                $this->guarded(fn () => $this->releaseLocally((string) $row->order_id, 'failed', 'authorization_timeout'), 'reconcile', (string) $row->order_id);

                continue;
            }
            $this->guarded(fn () => $this->authorize((string) $row->order_id), 'reconcile', (string) $row->order_id);
        }

        $abandoned = DB::table('payment_authorizations')
            ->where('status', 'requires_action')
            ->where('created_at', '<=', now()->subMinutes(self::ACTION_TIMEOUT_MINUTES))
            ->whereNotNull('stripe_payment_intent_id')
            ->limit($limit)
            ->get(['order_id', 'stripe_payment_intent_id']);
        foreach ($abandoned as $row) {
            $this->guarded(function () use ($row): void {
                $intent = $this->gateway->cancel((string) $row->stripe_payment_intent_id, 'order:'.$row->order_id.':cancel');
                $this->apply((string) $row->order_id, $intent, 'reconcile');
            }, 'reconcile', (string) $row->order_id);
        }

        return count($pending) + $abandoned->count();
    }

    /** The order's payment as the app sees it (no client secret: that is only in the live response). */
    public function step(string $orderId): PaymentStep
    {
        $status = (string) DB::table('orders')->where('id', $orderId)->value('payment_status');
        $failure = in_array($status, ['failed', 'voided'], true)
            ? DB::table('payment_authorizations')->where('order_id', $orderId)->orderByDesc('created_at')->value('last_error')
            : null;

        return new PaymentStep($status, null, $failure === null ? null : (string) $failure);
    }

    // ---------------------------------------------------------------- internals

    private function authorization(string $orderId): ?object
    {
        return DB::table('payment_authorizations')->where('order_id', $orderId)->orderByDesc('created_at')->first();
    }

    private function mismatch(object $order, object $auth, PaymentIntentResult $intent): ?string
    {
        if ($intent->id !== null && $auth->stripe_payment_intent_id !== null && $auth->stripe_payment_intent_id !== $intent->id) {
            return 'order_mismatch';
        }
        if (isset($intent->metadata['order_id']) && $intent->metadata['order_id'] !== (string) $order->id) {
            return 'order_mismatch';
        }
        if ($intent->currency !== '' && $intent->currency !== strtoupper((string) $order->currency)) {
            return 'currency_mismatch';
        }
        if ($intent->amount !== (int) $auth->authorized_minor) {
            return 'amount_mismatch';
        }
        if ($intent->isCaptured() && $intent->amountReceived > (int) $auth->authorized_minor) {
            return 'amount_mismatch';
        }

        return null;
    }

    private function transition(object $order, object $auth, string $authStatus, string $paymentStatus): void
    {
        DB::table('payment_authorizations')->where('id', $auth->id)->update(['status' => $authStatus]);
        DB::table('orders')->where('id', $order->id)->update(['payment_status' => $paymentStatus]);
    }

    private function markCaptured(object $order, object $auth, int $amountReceived): void
    {
        DB::table('payment_authorizations')->where('id', $auth->id)->update([
            'status' => 'captured',
            'captured_minor' => $amountReceived,
            'captured_at' => now(),
            'last_error' => null,
        ]);
        DB::table('orders')->where('id', $order->id)->update([
            'status' => 'paid',
            'payment_status' => 'captured',
            'captured_at' => now(),
            'delivered_at' => $order->delivered_at ?? now(),
        ]);
        if ($amountReceived !== (int) $order->total_minor) {
            $this->anomaly((string) $order->id, null, 'amount_mismatch', ['captured' => $amountReceived, 'total' => (int) $order->total_minor]);
        }
        $this->effects->onCaptured(DB::table('orders')->where('id', $order->id)->first(), $amountReceived);
    }

    private function release(object $order, object $auth, string $authStatus, string $reason): void
    {
        DB::table('payment_authorizations')->where('id', $auth->id)->update([
            'status' => $authStatus,
            'last_error' => mb_substr($reason, 0, 300),
        ]);
        DB::table('orders')->where('id', $order->id)->update([
            'payment_status' => $authStatus,
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);
        $this->effects->onReleased($order);
    }

    /** Closes a payment Stripe never acted on (or never answered for). */
    private function releaseLocally(string $orderId, string $authStatus, string $reason): PaymentStep
    {
        DB::transaction(function () use ($orderId, $authStatus, $reason): void {
            $order = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
            $auth = DB::table('payment_authorizations')->where('order_id', $orderId)->orderByDesc('created_at')->lockForUpdate()->first();
            if ($order === null || $auth === null || ! in_array($auth->status, ['pending', 'requires_action'], true)) {
                return;
            }
            $this->release($order, $auth, $authStatus, $reason);
        });

        return $this->step($orderId);
    }

    private function compensate(string $orderId, PaymentIntentResult $intent, string $action): void
    {
        $this->guarded(function () use ($orderId, $intent, $action): void {
            if ($action === 'cancel') {
                $this->gateway->cancel((string) $intent->id, 'order:'.$orderId.':cancel');

                return;
            }
            $this->gateway->refund((string) $intent->id, $intent->amountReceived, $intent->currency, 'order:'.$orderId.':late_capture_refund', 'requested_by_customer');
        }, 'compensate', $orderId);
    }

    private function deferAttempt(string $authorizationId, string $error): void
    {
        DB::update(
            "UPDATE payment_authorizations
                SET attempts = attempts + 1,
                    next_attempt_at = clock_timestamp() + LEAST(interval '15 minutes', interval '30 seconds' * power(2, LEAST(attempts, 5))),
                    last_error = ?
              WHERE id = ?",
            [mb_substr($error, 0, 300), $authorizationId],
        );
    }

    /** @param  array<string, mixed>  $detail */
    private function anomaly(?string $orderId, ?string $eventId, string $kind, array $detail): void
    {
        DB::table('payment_anomalies')->insert([
            'id' => (string) Str::uuid7(),
            'order_id' => $orderId,
            'stripe_event_id' => $eventId,
            'kind' => $kind,
            'detail' => json_encode($detail, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
        Log::warning('payments.anomaly', ['kind' => $kind, 'order_id' => $orderId, 'event_id' => $eventId]);
    }

    /** Runs one unit of background work; a failure is logged and left for the next run. */
    private function guarded(callable $work, string $job, string $orderId): void
    {
        try {
            $work();
        } catch (Throwable $exception) {
            Log::error('payments.'.$job.'.failed', ['order_id' => $orderId, 'error' => $exception::class]);
        }
    }
}

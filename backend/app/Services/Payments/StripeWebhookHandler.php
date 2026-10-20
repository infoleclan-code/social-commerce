<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Exceptions\ApiException;
use App\Support\Currency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\UnexpectedValueException;
use Stripe\Webhook;
use Stripe\WebhookSignature;
use Throwable;

/**
 * Stripe webhooks.
 *
 * Trust: an event is processed only with a valid Stripe-Signature over the exact raw body, within a
 * 5-minute timestamp tolerance (a captured request cannot be replayed later), at most 1 MB, and from the
 * same mode (live/test) as our secret key. Signed data is still checked against our rows: the intent
 * must map to one of our orders, with the authorized amount and currency (OrderPayments::apply).
 *
 * Delivery: Stripe delivers at least once and in any order. Every handler is idempotent and moves state
 * forward only, so duplicates and reordering are harmless; stripe_events records each event id once
 * (with its outcome) and short-circuits exact redeliveries.
 *
 * Handled: payment_intent.{amount_capturable_updated, succeeded, payment_failed, canceled,
 * requires_action, processing}, charge.refunded (cumulative amount_refunded), charge.dispute.{created,
 * updated, closed}. Everything else is recorded as ignored.
 */
final class StripeWebhookHandler
{
    public const MAX_PAYLOAD_BYTES = 1_048_576;

    public const TOLERANCE_SECONDS = 300;

    public function __construct(private readonly OrderPayments $payments) {}

    /** @return array<string, mixed> */
    public function verify(string $payload, string $signature): array
    {
        if (strlen($payload) > self::MAX_PAYLOAD_BYTES) {
            throw ApiException::payloadTooLarge();
        }
        $secret = (string) config('services.stripe.webhook_secret');
        if ($secret === '' || $signature === '' || strlen($signature) > 2_048) {
            throw ApiException::invalidSignature();
        }
        try {
            $event = Webhook::constructEvent($payload, $signature, $secret, self::TOLERANCE_SECONDS);
        } catch (SignatureVerificationException|UnexpectedValueException) {
            throw ApiException::invalidSignature();
        }

        return $event->toArray();
    }

    /**
     * Applies one verified event and returns its outcome: applied, ignored, anomaly, or duplicate.
     *
     * @param  array<string, mixed>  $event
     */
    public function handle(array $event): string
    {
        $eventId = (string) ($event['id'] ?? '');
        $type = (string) ($event['type'] ?? '');
        if (preg_match('/^evt_[A-Za-z0-9_]{1,76}$/', $eventId) !== 1 || preg_match('/^[a-z_.]{3,80}$/', $type) !== 1) {
            throw ApiException::invalidSignature();
        }
        if (DB::table('stripe_events')->where('id', $eventId)->exists()) {
            return 'duplicate';
        }
        $livemode = (bool) ($event['livemode'] ?? false);
        $outcome = $livemode !== $this->livemode()
            ? $this->anomaly(null, $eventId, 'livemode_mismatch', ['type' => $type])
            : $this->dispatch($eventId, $type, is_array($event['data'] ?? null) ? ($event['data']['object'] ?? null) : null);

        DB::table('stripe_events')->insertOrIgnore([
            'id' => $eventId,
            'type' => $type,
            'livemode' => $livemode,
            'outcome' => $outcome,
            'received_at' => now(),
        ]);

        return $outcome;
    }

    public static function sign(string $payload, string $secret, ?int $timestamp = null): string
    {
        return WebhookSignature::generateSignatureHeader($payload, $secret, $timestamp);
    }

    private function dispatch(string $eventId, string $type, mixed $object): string
    {
        if (! is_array($object)) {
            return 'ignored';
        }
        if (str_starts_with($type, 'payment_intent.') && ($object['object'] ?? null) === 'payment_intent') {
            return $this->paymentIntent($eventId, $object);
        }
        if ($type === 'charge.refunded' && ($object['object'] ?? null) === 'charge') {
            return $this->refunded($eventId, $object);
        }
        if (str_starts_with($type, 'charge.dispute.') && ($object['object'] ?? null) === 'dispute') {
            return $this->dispute($eventId, $object);
        }

        return 'ignored';
    }

    /** @param  array<string, mixed>  $object */
    private function paymentIntent(string $eventId, array $object): string
    {
        try {
            $intent = PaymentIntentResult::fromArray($object);
        } catch (Throwable) {
            return $this->anomaly(null, $eventId, 'amount_mismatch', ['reason' => 'unreadable_amount']);
        }
        $orderId = $this->orderFor($intent->id, $intent->metadata);
        if ($orderId === null) {
            return $this->anomaly(null, $eventId, 'unknown_intent', ['payment_intent' => $intent->id]);
        }
        $before = DB::table('payment_anomalies')->where('stripe_event_id', $eventId)->count();
        $this->payments->apply($orderId, $intent, 'webhook', $eventId);

        return DB::table('payment_anomalies')->where('stripe_event_id', $eventId)->count() > $before ? 'anomaly' : 'applied';
    }

    /** @param  array<string, mixed>  $charge */
    private function refunded(string $eventId, array $charge): string
    {
        $orderId = $this->orderFor(is_string($charge['payment_intent'] ?? null) ? $charge['payment_intent'] : null, []);
        if ($orderId === null) {
            return $this->anomaly(null, $eventId, 'unknown_intent', ['charge' => $charge['id'] ?? null]);
        }
        $order = DB::table('orders')->where('id', $orderId)->first(['currency']);
        $currency = strtoupper((string) ($charge['currency'] ?? ''));
        if ($currency !== strtoupper((string) $order->currency)) {
            return $this->anomaly($orderId, $eventId, 'currency_mismatch', ['currency' => $currency]);
        }
        $this->payments->applyRefund($orderId, Currency::fromStripe((int) ($charge['amount_refunded'] ?? 0), $currency), true);

        return 'applied';
    }

    /** @param  array<string, mixed>  $dispute */
    private function dispute(string $eventId, array $dispute): string
    {
        $disputeId = (string) ($dispute['id'] ?? '');
        $orderId = $this->orderFor(is_string($dispute['payment_intent'] ?? null) ? $dispute['payment_intent'] : null, []);
        if ($orderId === null || preg_match('/^dp_[A-Za-z0-9_]{1,100}$/', $disputeId) !== 1) {
            return $this->anomaly(null, $eventId, 'unknown_intent', ['dispute' => $disputeId]);
        }
        $currency = strtoupper((string) ($dispute['currency'] ?? ''));
        $status = match ((string) ($dispute['status'] ?? '')) {
            'won', 'warning_closed' => 'won',
            'lost' => 'lost',
            default => 'open',
        };
        $this->payments->applyDispute(
            $orderId,
            $disputeId,
            $status,
            Currency::fromStripe((int) ($dispute['amount'] ?? 0), $currency === '' ? 'USD' : $currency),
            $currency === '' ? 'USD' : $currency,
            is_string($dispute['reason'] ?? null) ? $dispute['reason'] : null,
        );

        return 'applied';
    }

    /**
     * Our order for a PaymentIntent: by the intent id we stored, or (when our authorize response was lost)
     * by the order id we put in its metadata, which must also name the same buyer.
     *
     * @param  array<string, string>  $metadata
     */
    private function orderFor(?string $paymentIntentId, array $metadata): ?string
    {
        if ($paymentIntentId !== null && $paymentIntentId !== '') {
            $id = DB::table('orders')->where('stripe_payment_intent_id', $paymentIntentId)->value('id');
            if ($id !== null) {
                return (string) $id;
            }
        }
        $orderId = $metadata['order_id'] ?? null;
        if (! is_string($orderId) || ! Str::isUuid($orderId)) {
            return null;
        }
        $order = DB::table('orders')->where('id', $orderId)->first(['id', 'buyer_id', 'stripe_payment_intent_id']);
        if ($order === null || (string) $order->buyer_id !== ($metadata['buyer_id'] ?? null) || $order->stripe_payment_intent_id !== null) {
            return null;
        }

        return (string) $order->id;
    }

    /** @param  array<string, mixed>  $detail */
    private function anomaly(?string $orderId, string $eventId, string $kind, array $detail): string
    {
        DB::table('payment_anomalies')->insert([
            'id' => (string) Str::uuid7(),
            'order_id' => $orderId,
            'stripe_event_id' => $eventId,
            'kind' => $kind,
            'detail' => json_encode($detail, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        return 'anomaly';
    }

    private function livemode(): bool
    {
        $secret = (string) config('services.stripe.secret');

        return str_starts_with($secret, 'sk_live_') || str_starts_with($secret, 'rk_live_');
    }
}

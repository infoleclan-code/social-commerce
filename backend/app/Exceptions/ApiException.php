<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Support\Currency;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A refusal the client can act on, rendered as the API error contract:
 * { "message": "...", "code": "POST_NOT_FOUND", "errors": {...} } with a fitting HTTP status.
 *
 * Laravel calls render() automatically. Codes are stable identifiers the mobile app switches on;
 * messages are for humans and may change.
 */
final class ApiException extends RuntimeException
{
    /** @param  array<string, list<string>>  $errors */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $errors = [],
        public readonly array $meta = [],
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        $body = ['message' => $this->getMessage(), 'code' => $this->errorCode];
        if ($this->errors !== []) {
            $body['errors'] = $this->errors;
        }
        if ($this->meta !== []) {
            $body['meta'] = $this->meta;
        }

        return new JsonResponse($body, $this->status);
    }

    // ------------------------------------------------------------------ feed

    public static function socialOptInRequired(): self
    {
        return new self(403, 'SOCIAL_OPT_IN_REQUIRED', 'Turn on the social feed in your privacy settings to post or comment.');
    }

    public static function postNotFound(): self
    {
        return new self(404, 'POST_NOT_FOUND', 'This post does not exist or is not visible to you.');
    }

    public static function invalidCursor(): self
    {
        return new self(422, 'INVALID_CURSOR', 'The pagination cursor is invalid.', ['cursor' => ['The pagination cursor is invalid.']]);
    }

    public static function mediaRejected(string $reason): self
    {
        return new self(422, 'MEDIA_REJECTED', $reason, ['media' => [$reason]]);
    }

    public static function commentNotAllowed(string $reason): self
    {
        return new self(422, 'COMMENT_REJECTED', $reason, ['parent_id' => [$reason]]);
    }

    public static function profileNotFound(): self
    {
        return new self(404, 'PROFILE_NOT_FOUND', 'This profile does not exist or is not visible to you.');
    }

    public static function hashtagInvalid(): self
    {
        return new self(422, 'HASHTAG_INVALID', 'That hashtag is not valid.', ['tag' => ['Use letters, numbers, or underscore.']]);
    }

    public static function outOfStock(): self
    {
        return new self(409, 'OUT_OF_STOCK', 'This item just sold out.');
    }

    public static function idempotencyConflict(): self
    {
        return new self(422, 'IDEMPOTENCY_KEY_REUSED', 'This checkout key was already used for a different product.');
    }

    public static function squadClosed(): self
    {
        return new self(409, 'SQUAD_CLOSED', 'That group buy is full or has ended.');
    }

    public static function couponInvalid(): self
    {
        return new self(422, 'COUPON_INVALID', 'That perk is no longer available.');
    }

    public static function notEnoughCoins(): self
    {
        return new self(422, 'NOT_ENOUGH_COINS', 'You need 100 coins to open a loot box.');
    }

    public static function notCancellable(): self
    {
        return new self(409, 'NOT_CANCELLABLE', 'This authorization can no longer be voided.');
    }

    public static function imageRejected(): self
    {
        return new self(422, 'IMAGE_REJECTED', 'That image could not be read.', ['image' => ['Use a JPEG, PNG, or WebP.']]);
    }

    public static function giftClosed(): self
    {
        return new self(409, 'GIFT_CLOSED', 'This gift link is no longer open.');
    }

    public static function missionIncomplete(): self
    {
        return new self(422, 'MISSION_INCOMPLETE', 'Finish that action today before claiming the mission.');
    }

    public static function bidTooLow(): self
    {
        return new self(409, 'BID_TOO_LOW', 'Bid above the current price.');
    }

    public static function spendLimit(): self
    {
        return new self(409, 'SPEND_LIMIT', 'This order would pass the monthly limit you set.');
    }

    public static function accountPaused(): self
    {
        return new self(409, 'ACCOUNT_PAUSED', 'Purchases are paused on this account.');
    }

    public static function streaksOff(): self
    {
        return new self(422, 'STREAKS_OFF', 'Streaks stay off until you turn them on.');
    }

    public static function paymentsUnavailable(): self
    {
        return new self(503, 'PAYMENTS_UNAVAILABLE', 'Card payments are not configured.');
    }

    public static function paymentMethodRequired(): self
    {
        return new self(422, 'PAYMENT_METHOD_REQUIRED', 'Save a card before paying.');
    }

    public static function ageAssuranceRequired(): self
    {
        return new self(422, 'AGE_ASSURANCE_REQUIRED', 'Confirm you are 18 or older before opening a blind box. A date of birth is not stored.');
    }

    public static function invalidSignature(): self
    {
        return new self(400, 'INVALID_SIGNATURE', 'The webhook signature is not valid.');
    }

    public static function reviewNotVerified(): self
    {
        return new self(403, 'REVIEW_NOT_VERIFIED', 'Reviews are limited to people who bought this item.');
    }

    public static function answerNotVerified(): self
    {
        return new self(403, 'OWNER_NOT_VERIFIED', 'Only someone who bought this item can answer.');
    }

    // ------------------------------------------------------------------ hardening

    public static function idempotencyKeyRequired(): self
    {
        return new self(422, 'IDEMPOTENCY_KEY_REQUIRED', 'Send an Idempotency-Key header (8-80 letters, digits, - or _).');
    }

    public static function idempotencyKeyReused(): self
    {
        return new self(422, 'IDEMPOTENCY_KEY_REUSED', 'This Idempotency-Key was already used for a different request.');
    }

    public static function requestInProgress(): self
    {
        return new self(409, 'REQUEST_IN_PROGRESS', 'The same request is still being processed. Retry in a moment.');
    }

    public static function priceChanged(int $totalMinor, string $currency): self
    {
        return new self(409, 'PRICE_CHANGED', 'The price changed since you last saw it.', [], [
            'total' => Currency::money($totalMinor, $currency),
        ]);
    }

    public static function orderNotFound(): self
    {
        return new self(404, 'ORDER_NOT_FOUND', 'This order does not exist or is not yours.');
    }

    public static function squadNotFound(): self
    {
        return new self(404, 'SQUAD_NOT_FOUND', 'This group buy does not exist or you are not in it.');
    }

    public static function refundRejected(): self
    {
        return new self(409, 'REFUND_REJECTED', 'This payment cannot be refunded again.');
    }

    public static function setupInvalid(): self
    {
        return new self(422, 'PAYMENT_METHOD_INVALID', 'That card could not be confirmed for this account.');
    }

    public static function payloadTooLarge(): self
    {
        return new self(413, 'PAYLOAD_TOO_LARGE', 'The request body is too large.');
    }

    public static function imageHashMismatch(): self
    {
        return new self(422, 'IMAGE_HASH_MISMATCH', 'The image did not arrive intact. Try again.', ['image_sha256' => ['Does not match the uploaded bytes.']]);
    }

    public static function imageInvalid(string $reason): self
    {
        return new self(422, 'IMAGE_REJECTED', $reason, ['image' => [$reason]]);
    }

    public static function orderLimit(): self
    {
        return new self(422, 'ORDER_LIMIT', 'This order is above the maximum amount for one purchase.');
    }

    public static function blindBoxPulled(): self
    {
        return new self(409, 'BLIND_BOX_PULLED', 'You already opened a blind box today.');
    }

    public static function currencyLocked(): self
    {
        return new self(422, 'CURRENCY_LOCKED', 'A listing keeps the currency it was created with.');
    }

    public static function gtinInvalid(): self
    {
        return new self(422, 'GTIN_INVALID', 'That barcode (GTIN) is not valid.', ['gtin' => ['Check the digits and the check digit.']]);
    }

    public static function productNotFound(): self
    {
        return new self(404, 'PRODUCT_NOT_FOUND', 'This product does not exist.');
    }
}

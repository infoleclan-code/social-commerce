<?php

declare(strict_types=1);

namespace App\Services\Payments;

use RuntimeException;

/**
 * The request may or may not have reached Stripe (timeout, dropped connection, 5xx). Nothing may be
 * assumed: the caller keeps the payment pending and retries with the SAME idempotency key, which
 * either replays Stripe's stored result or performs the request once.
 */
final class PaymentOutcomeUnknown extends RuntimeException {}

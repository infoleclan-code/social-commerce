<?php

declare(strict_types=1);

namespace App\Services\Payments;

use RuntimeException;

/** Stripe definitely did not act (not configured, rejected credentials, rate limited after retries). */
final class PaymentGatewayUnavailable extends RuntimeException {}

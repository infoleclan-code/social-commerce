<?php

declare(strict_types=1);

namespace App\Services\Payments;

use RuntimeException;

/** Stripe refused a request for a definite reason (for example a refund larger than what is left). */
final class PaymentRejected extends RuntimeException
{
    public function __construct(public readonly string $stripeCode, string $message = '')
    {
        parent::__construct($message !== '' ? $message : 'Stripe rejected the request: '.$stripeCode);
    }
}

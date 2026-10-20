<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * ISO 4217 minor units, and the single conversion point to Stripe API amounts.
 *
 * Amounts in this app are integer minor units of the order currency. Stripe's exponent equals ISO's
 * for nearly every currency (USD 1099 = 10.99, JPY 500 = 500 yen); ISK and UGX are zero-decimal in ISO
 * but Stripe expects them as two-decimal values ending in 00 (docs.stripe.com/currencies). Check that
 * page before enabling a new currency and extend the maps here.
 */
final class Currency
{
    private const ZERO_DECIMAL = [
        'BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV',
        'XAF', 'XOF', 'XPF',
    ];

    private const THREE_DECIMAL = ['BHD', 'JOD', 'KWD', 'OMR', 'TND'];

    private const STRIPE_EXPONENT_OVERRIDES = ['ISK' => 2, 'UGX' => 2];

    public static function minorUnit(string $currency): int
    {
        $code = self::code($currency);
        if (in_array($code, self::ZERO_DECIMAL, true)) {
            return 0;
        }

        return in_array($code, self::THREE_DECIMAL, true) ? 3 : 2;
    }

    public static function toStripe(int $minor, string $currency): int
    {
        return $minor * (10 ** self::shift($currency));
    }

    public static function fromStripe(int $amount, string $currency): int
    {
        $factor = 10 ** self::shift($currency);
        if ($amount % $factor !== 0) {
            throw new InvalidArgumentException("Stripe amount {$amount} {$currency} has fractional units.");
        }

        return intdiv($amount, $factor);
    }

    /** @return array{amount_minor: int, currency: string, minor_unit: int} */
    public static function money(int $minor, string $currency): array
    {
        $code = self::code($currency);

        return ['amount_minor' => $minor, 'currency' => $code, 'minor_unit' => self::minorUnit($code)];
    }

    public static function code(string $currency): string
    {
        $code = strtoupper(trim($currency));
        if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
            throw new InvalidArgumentException('Currency must be an ISO 4217 code.');
        }

        return $code;
    }

    private static function shift(string $currency): int
    {
        $code = self::code($currency);

        return (self::STRIPE_EXPONENT_OVERRIDES[$code] ?? self::minorUnit($code)) - self::minorUnit($code);
    }
}

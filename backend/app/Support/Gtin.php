<?php

declare(strict_types=1);

namespace App\Support;

/** GTIN-8/12/13/14 with a valid GS1 check digit. */
final class Gtin
{
    public static function valid(string $code): bool
    {
        if (preg_match('/^(\d{8}|\d{12}|\d{13}|\d{14})$/', $code) !== 1) {
            return false;
        }
        $digits = array_map('intval', str_split($code));
        $check = array_pop($digits);
        $sum = 0;
        foreach (array_reverse($digits) as $index => $digit) {
            $sum += $digit * ($index % 2 === 0 ? 3 : 1);
        }

        return (10 - $sum % 10) % 10 === $check;
    }
}

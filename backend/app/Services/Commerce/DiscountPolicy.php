<?php

declare(strict_types=1);

namespace App\Services\Commerce;

/**
 * One rule for every discount: the better of the flash drop and the squad discount, plus one coupon,
 * capped at commerce.max_discount_bps. Stacking a drop, a full squad and a spin coupon can no longer
 * reach 80% off.
 */
final class DiscountPolicy
{
    public static function combine(int $dropBps, int $squadBps, int $couponBps): int
    {
        $cap = max(0, min(9000, (int) config('commerce.max_discount_bps', 5000)));
        $base = max(0, $dropBps, $squadBps);

        return min($cap, $base + max(0, $couponBps));
    }
}

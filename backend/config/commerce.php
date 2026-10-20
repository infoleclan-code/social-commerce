<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Commerce safety limits
|--------------------------------------------------------------------------
| Business limits that bound what a bug or an attacker can do with money. Change them deliberately:
| every value here is enforced on the server, never trusted from the client.
*/

return [
    // Seconds between authorization and capture during which the buyer can undo for free.
    'undo_seconds' => (int) env('COMMERCE_UNDO_SECONDS', 120),

    // Discounts: max(flash drop, squad) + one coupon, never more than this off the list price.
    'max_discount_bps' => (int) env('COMMERCE_MAX_DISCOUNT_BPS', 5000),

    // Creator + referral commissions together, as a share of what the buyer paid.
    'max_commission_bps' => (int) env('COMMERCE_MAX_COMMISSION_BPS', 2000),

    // Largest single order in minor units (fraud exposure per tap).
    'max_order_minor' => (int) env('COMMERCE_MAX_ORDER_MINOR', 500_000),

    'flash_drops' => [
        // Created by the scheduler (drops:rotate), never by an API request.
        'enabled' => (bool) env('COMMERCE_FLASH_DROPS', true),
        'discount_bps' => (int) env('COMMERCE_FLASH_DROP_DISCOUNT_BPS', 4000),
        'duration_seconds' => (int) env('COMMERCE_FLASH_DROP_SECONDS', 60),
        'max_units' => 8,
    ],

    'price_protection' => [
        'window_days' => 14,
        // A lower price must have stayed on sale this long before it pays out (no flash-dip payouts).
        'min_hold_hours' => (int) env('COMMERCE_PRICE_PROTECTION_HOLD_HOURS', 24),
        // Never pay back more than this share of what the buyer paid per unit.
        'max_payout_bps' => 5000,
    ],

    'visual_search' => [
        'luma_threshold' => 0.85,
        'structure_threshold' => 0.80,
        'candidates' => 64,
        'ef_search' => (int) env('VISUAL_SEARCH_EF_SEARCH', 100),
        'max_scan_tuples' => (int) env('VISUAL_SEARCH_MAX_SCAN_TUPLES', 20000),
        'max_upload_bytes' => 8 * 1024 * 1024,
        'max_pixels' => 16_000_000,
        // Two listings from different sellers this close (Hamming distance of a 64-bit dHash) are
        // flagged as a likely copied photo.
        'duplicate_hamming' => 6,
    ],

    'auto_tag' => [
        'attach_at' => 0.85,
        'suggest_at' => 0.72,
        // The best match must beat the runner-up by this margin to attach on its own.
        'attach_margin' => 0.03,
    ],

    'cache' => [
        'feed_pool_size' => 1000,
        'feed_tag_pool_size' => 200,
        'feed_fresh_seconds' => 20,
        'feed_stale_seconds' => 60,
        'offers_seconds' => 60,
        'graph_seconds' => 600,
    ],
];

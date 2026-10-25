<?php

declare(strict_types=1);

return [
    /*
     * fake: deterministic offers for local development and tests.
     * serpapi: Google Shopping via SerpAPI (https://serpapi.com/google-shopping-api).
     * A missing key never falls back to fake results. The search returns 503 instead.
     */
    'provider' => env('COMPARE_PROVIDER', 'fake'),
    'serpapi_key' => env('SERPAPI_API_KEY', ''),
    'cache_ttl' => (int) env('COMPARE_CACHE_TTL', 600),
    'timeout_seconds' => (int) env('COMPARE_TIMEOUT', 4),

    /*
     * Appended to the outbound store URL when both are set. The stored click row
     * keeps the host and the offer key, not the tagged URL.
     */
    'affiliate_param' => env('AFFILIATE_QUERY_PARAM', ''),
    'affiliate_id' => env('AFFILIATE_ID', ''),

    /*
     * Approximate static rates, units per 1 USD times 1_000_000. Not a live FX feed.
     * USD 1.00, EUR 0.92, GBP 0.79.
     */
    'rates' => [
        'USD' => 1_000_000,
        'EUR' => 920_000,
        'GBP' => 790_000,
    ],
];

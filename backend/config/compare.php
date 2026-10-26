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
    'timeout_seconds' => (int) env('COMPARE_TIMEOUT', 12),

    /*
     * Appended to the outbound store URL when both are set. The stored click row
     * keeps the host and the offer key, not the tagged URL.
     */
    'affiliate_param' => env('AFFILIATE_QUERY_PARAM', ''),
    'affiliate_id' => env('AFFILIATE_ID', ''),

    /*
     * Live rates are refreshed by fx:refresh from FX_FEED_URL (Frankfurter v1 or v2).
     * These static rates are the fallback when the feed has not succeeded yet.
     * Units per 1 USD times 1_000_000. USD 1.00, EUR 0.92, GBP 0.79.
     */
    'fx_url' => env('FX_FEED_URL', 'https://api.frankfurter.dev/v1/latest?from=USD'),
    'rates' => [
        'USD' => 1_000_000,
        'EUR' => 920_000,
        'GBP' => 790_000,
    ],
];

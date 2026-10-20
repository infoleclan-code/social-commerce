<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        // Pin the API version your webhook endpoint is configured with (Dashboard > Developers).
        'api_version' => env('STRIPE_API_VERSION'),
        'timeout_seconds' => (int) env('STRIPE_TIMEOUT_SECONDS', 20),
        'connect_timeout_seconds' => (int) env('STRIPE_CONNECT_TIMEOUT_SECONDS', 5),
        'max_network_retries' => (int) env('STRIPE_MAX_NETWORK_RETRIES', 2),
    ],

    // Optional vision model that names the scanned product (category, brand, colors). The photo is
    // sent to that provider: list it as a processor (DPA) before enabling it in production.
    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'vision_model' => env('OPENAI_VISION_MODEL', 'gpt-4o'),
    ],

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'vision_model' => env('ANTHROPIC_VISION_MODEL', 'claude-3-5-sonnet-latest'),
    ],

    // Seconds the scan request waits for the vision model before using local colors only.
    'vision' => [
        'timeout_seconds' => (int) env('VISION_TIMEOUT_SECONDS', 8),
        'connect_timeout_seconds' => (int) env('VISION_CONNECT_TIMEOUT_SECONDS', 3),
    ],

];

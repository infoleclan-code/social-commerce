<?php

declare(strict_types=1);

return [
    /*
     * fake: a fixed reply for local development and tests.
     * http: an OpenAI-compatible chat completions endpoint. An empty key does
     * not fall back to fake replies.
     */
    'provider' => env('LLM_PROVIDER', 'fake'),
    'api_key' => env('LLM_API_KEY', ''),
    'model' => env('LLM_MODEL', 'gpt-4o-mini'),
    'url' => env('LLM_API_URL', 'https://api.openai.com/v1/chat/completions'),
];

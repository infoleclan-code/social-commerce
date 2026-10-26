<?php

declare(strict_types=1);

namespace App\Services\Assistant;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/** OpenAI-compatible chat completions. The key is sent as a bearer token and not logged. */
final class HttpLlmClient implements LlmClient
{
    public function __construct(
        private readonly string $key,
        private readonly string $url,
        private readonly string $model,
    ) {}

    public function complete(string $system, string $user): string
    {
        $response = Http::timeout(12)
            ->withToken($this->key)
            ->acceptJson()
            ->post($this->url, [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
                'response_format' => ['type' => 'json_object'],
            ]);
        $content = $response->json('choices.0.message.content');
        if (! $response->successful() || ! is_string($content) || $content === '') {
            throw new RuntimeException('LLM request failed');
        }
        $content = trim($content);
        if (str_starts_with($content, '```')) {
            $content = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $content) ?? $content;
        }

        return trim($content);
    }
}

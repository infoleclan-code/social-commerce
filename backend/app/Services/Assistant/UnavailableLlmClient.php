<?php

declare(strict_types=1);

namespace App\Services\Assistant;

use RuntimeException;

/** Selected when the HTTP model is configured without a key. */
final class UnavailableLlmClient implements LlmClient
{
    public function complete(string $system, string $user): string
    {
        throw new RuntimeException('LLM key is not configured');
    }
}

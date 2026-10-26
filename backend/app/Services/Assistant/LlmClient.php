<?php

declare(strict_types=1);

namespace App\Services\Assistant;

interface LlmClient
{
    /** A JSON object as text. The caller validates the shape. */
    public function complete(string $system, string $user): string;
}

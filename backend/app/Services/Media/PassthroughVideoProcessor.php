<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Services\Media\Contracts\VideoProcessor;

/**
 * Local development without ffmpeg: stores the upload unchanged and trusts the client's duration.
 * Do not use in production - location metadata in phone videos is NOT stripped.
 */
final class PassthroughVideoProcessor implements VideoProcessor
{
    public function process(string $path, ?int $clientDurationMs): ProcessedVideo
    {
        return new ProcessedVideo($path, $clientDurationMs, null, null);
    }
}

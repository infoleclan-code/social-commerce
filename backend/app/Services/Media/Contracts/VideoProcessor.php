<?php

declare(strict_types=1);

namespace App\Services\Media\Contracts;

use App\Services\Media\ProcessedVideo;

/**
 * Prepares an uploaded video for the feed. The returned file is what gets stored and streamed.
 */
interface VideoProcessor
{
    /**
     * @param  string  $path  local path of the uploaded file
     * @param  int|null  $clientDurationMs  duration reported by the app (used only when the processor cannot probe)
     *
     * @throws \App\Exceptions\ApiException when the file is not a playable video
     */
    public function process(string $path, ?int $clientDurationMs): ProcessedVideo;
}

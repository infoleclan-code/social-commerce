<?php

declare(strict_types=1);

namespace App\Services\Media;

/**
 * A video ready to store: $path is a local file (sanitized copy or the original), dimensions are the
 * DISPLAY dimensions (rotation applied), $posterPath an extracted frame when the processor can make one.
 */
final readonly class ProcessedVideo
{
    public function __construct(
        public string $path,
        public ?int $durationMs,
        public ?int $width,
        public ?int $height,
        public ?string $posterPath = null,
    ) {}
}

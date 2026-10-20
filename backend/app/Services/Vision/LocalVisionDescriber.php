<?php

declare(strict_types=1);

namespace App\Services\Vision;

use App\Services\Media\ColorPalette;

/** Colors from the pixels. Category and brand stay empty until a vision model is configured. */
final class LocalVisionDescriber implements VisionDescriber
{
    public function describe(string $bytes): array
    {
        return [
            'category' => null,
            'colors' => ColorPalette::dominant($bytes),
            'brand' => null,
            'features' => [],
            'confidence' => 0.0,
            'source' => 'local',
        ];
    }
}

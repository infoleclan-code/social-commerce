<?php

declare(strict_types=1);

namespace App\Services\Media;

/**
 * @deprecated Derived from the luminance grid, so not an independent check. Use VisualEmbedding.
 *
 * 32-d gradient grid. A second, independent view of the same frame:
 * luminance can match a flat color while this vector stays near zero.
 */
final class StructureEmbedding
{
    /** @return list<float> */
    public static function fromBytes(string $bytes): array
    {
        $luminance = ImageEmbedding::fromBytes($bytes);
        $vector = [];
        $count = count($luminance);
        for ($i = 0; $i < $count; $i++) {
            $next = $luminance[($i + 1) % $count];
            $vector[] = abs($luminance[$i] - $next);
        }

        return ImageEmbedding::normalize($vector);
    }
}

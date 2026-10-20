<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Exceptions\ApiException;

/**
 * 32-d luminance grid (visual_v1).
 *
 * @deprecated Not zero-mean (a flat image matched everything) and decoded without a pixel budget.
 *             Use VisualEmbedding (visual_v2) through ImageGuard. Kept for products.embedding rows.
 */
final class ImageEmbedding
{
    public const DIMENSIONS = 32;

    /** @return list<float> */
    public static function fromBytes(string $bytes): array
    {
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw ApiException::imageRejected();
        }
        $width = imagesx($image);
        $height = imagesy($image);
        $vector = [];
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $px = min($width - 1, (int) (($x + 0.5) * $width / 8));
                $py = min($height - 1, (int) (($y + 0.5) * $height / 4));
                $rgb = imagecolorat($image, $px, $py);
                $red = ($rgb >> 16) & 255;
                $green = ($rgb >> 8) & 255;
                $blue = $rgb & 255;
                $vector[] = (0.299 * $red + 0.587 * $green + 0.114 * $blue) / 255;
            }
        }
        imagedestroy($image);

        return self::normalize($vector);
    }

    /** @param  list<float>  $vector
     * @return list<float>
     */
    public static function normalize(array $vector): array
    {
        $sum = 0.0;
        foreach ($vector as $value) {
            $sum += $value * $value;
        }
        $norm = sqrt($sum);
        if ($norm == 0.0) {
            return $vector;
        }

        return array_map(fn (float $value): float => $value / $norm, $vector);
    }

    /** @param  list<float>  $vector */
    public static function literal(array $vector): string
    {
        return '{'.implode(',', array_map(fn (float $value): string => sprintf('%.6F', $value), $vector)).'}';
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Exceptions\ApiException;
use GdImage;

/**
 * Computes a VisualSignature from a decoded image. Pure PHP + GD on a 64x64 analysis canvas, so it
 * costs a few milliseconds and needs no model server; the pgvector schema keeps room for a learned
 * embedding beside it (see docs/SECURITY_HARDENING.md, "Visual search at scale").
 */
final class VisualEmbedding
{
    public const MODEL = 'visual_v2';

    public const DIMENSIONS = 64;

    private const CANVAS = 64;

    /** Below this standard deviation (0..1 luminance) a photo carries no usable signal. */
    private const FLAT_STD = 0.015;

    public static function fromBytes(string $bytes, ?string $claimedSha256 = null): VisualSignature
    {
        return self::fromImage(ImageGuard::decode($bytes, $claimedSha256));
    }

    public static function fromImage(DecodedImage $image): VisualSignature
    {
        $pixels = self::luminance($image->gd, $image->width, $image->height, self::CANVAS, self::CANVAS);

        return new VisualSignature(
            self::lumaVector($pixels),
            self::structureVector($pixels),
            self::dhash($image->gd, $image->width, $image->height),
            $image->sha256,
        );
    }

    /** @param  list<list<float>>  $pixels
     * @return list<float>
     */
    private static function lumaVector(array $pixels): array
    {
        $vector = [];
        $cell = intdiv(self::CANVAS, 8);
        for ($by = 0; $by < 8; $by++) {
            for ($bx = 0; $bx < 8; $bx++) {
                $sum = 0.0;
                for ($y = $by * $cell; $y < ($by + 1) * $cell; $y++) {
                    for ($x = $bx * $cell; $x < ($bx + 1) * $cell; $x++) {
                        $sum += $pixels[$y][$x];
                    }
                }
                $vector[] = $sum / ($cell * $cell);
            }
        }
        $mean = array_sum($vector) / count($vector);
        $centered = array_map(fn (float $value): float => $value - $mean, $vector);
        $variance = array_sum(array_map(fn (float $value): float => $value * $value, $centered)) / count($centered);
        if (sqrt($variance) < self::FLAT_STD) {
            throw ApiException::imageInvalid('The photo is too plain to match. Frame the item and try again.');
        }

        return self::normalize($centered);
    }

    /** @param  list<list<float>>  $pixels
     * @return list<float>
     */
    private static function structureVector(array $pixels): array
    {
        $size = self::CANVAS;
        $cell = intdiv($size, 4);
        $vector = array_fill(0, 64, 0.0);
        $total = 0.0;
        for ($y = 1; $y < $size - 1; $y++) {
            for ($x = 1; $x < $size - 1; $x++) {
                $gx = ($pixels[$y - 1][$x + 1] + 2 * $pixels[$y][$x + 1] + $pixels[$y + 1][$x + 1])
                    - ($pixels[$y - 1][$x - 1] + 2 * $pixels[$y][$x - 1] + $pixels[$y + 1][$x - 1]);
                $gy = ($pixels[$y + 1][$x - 1] + 2 * $pixels[$y + 1][$x] + $pixels[$y + 1][$x + 1])
                    - ($pixels[$y - 1][$x - 1] + 2 * $pixels[$y - 1][$x] + $pixels[$y - 1][$x + 1]);
                $magnitude = sqrt($gx * $gx + $gy * $gy);
                if ($magnitude < 1e-6) {
                    continue;
                }
                $angle = atan2($gy, $gx);
                if ($angle < 0) {
                    $angle += M_PI;
                }
                $bin = (int) floor(($angle / M_PI) * 4 + 0.5) % 4;
                $index = (intdiv($y, $cell) * 4 + intdiv($x, $cell)) * 4 + $bin;
                $vector[$index] += $magnitude;
                $total += $magnitude;
            }
        }
        if ($total < 1e-3) {
            throw ApiException::imageInvalid('The photo is too plain to match. Frame the item and try again.');
        }

        return self::normalize($vector);
    }

    private static function dhash(GdImage $source, int $width, int $height): string
    {
        $pixels = self::luminance($source, $width, $height, 9, 8);
        $bits = '';
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $bits .= $pixels[$y][$x] > $pixels[$y][$x + 1] ? '1' : '0';
            }
        }

        return $bits;
    }

    /** @return list<list<float>> luminance 0..1, transparency flattened on white */
    private static function luminance(GdImage $source, int $width, int $height, int $targetWidth, int $targetHeight): array
    {
        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagealphablending($canvas, true);
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
        $rows = [];
        for ($y = 0; $y < $targetHeight; $y++) {
            $row = [];
            for ($x = 0; $x < $targetWidth; $x++) {
                $rgb = imagecolorat($canvas, $x, $y);
                $row[] = (0.299 * (($rgb >> 16) & 255) + 0.587 * (($rgb >> 8) & 255) + 0.114 * ($rgb & 255)) / 255;
            }
            $rows[] = $row;
        }
        imagedestroy($canvas);

        return $rows;
    }

    /** @param  list<float>  $vector
     * @return list<float>
     */
    private static function normalize(array $vector): array
    {
        $norm = sqrt(array_sum(array_map(fn (float $value): float => $value * $value, $vector)));

        return $norm == 0.0 ? $vector : array_map(fn (float $value): float => $value / $norm, $vector);
    }
}

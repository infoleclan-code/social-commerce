<?php

declare(strict_types=1);

namespace App\Services\Media;

/**
 * What a photo looks like to visual search, model visual_v2:
 *  - luma: 8x8 block luminance, zero-mean and unit-length (cosine = Pearson correlation, so brightness
 *    and contrast changes do not matter and a flat image has no signature at all);
 *  - structure: 4x4 cells x 4 gradient-orientation bins (Sobel), an independent view of edges and shape;
 *  - dhash: 64-bit difference hash, compared by Hamming distance to catch re-uploads of the same photo
 *    (resized, recompressed, lightly edited) across sellers.
 */
final readonly class VisualSignature
{
    /**
     * @param  list<float>  $luma
     * @param  list<float>  $structure
     */
    public function __construct(
        public array $luma,
        public array $structure,
        public string $dhash,
        public string $sha256,
    ) {}

    /** @param  list<float>  $vector */
    public static function vectorLiteral(array $vector): string
    {
        return '['.implode(',', array_map(fn (float $value): string => sprintf('%.7F', $value), $vector)).']';
    }

    /** @param  list<float>  $vector */
    public static function arrayLiteral(array $vector): string
    {
        return '{'.implode(',', array_map(fn (float $value): string => sprintf('%.7F', $value), $vector)).'}';
    }

    /** @param  list<float>  $left
     * @param  list<float>  $right
     */
    public static function cosine(array $left, array $right): float
    {
        $dot = 0.0;
        $a = 0.0;
        $b = 0.0;
        foreach ($left as $i => $value) {
            $dot += $value * $right[$i];
            $a += $value * $value;
            $b += $right[$i] * $right[$i];
        }

        return $a == 0.0 || $b == 0.0 ? 0.0 : $dot / (sqrt($a) * sqrt($b));
    }

    public static function hamming(string $left, string $right): int
    {
        $distance = 0;
        for ($i = 0, $n = min(strlen($left), strlen($right)); $i < $n; $i++) {
            $distance += $left[$i] === $right[$i] ? 0 : 1;
        }

        return $distance + abs(strlen($left) - strlen($right));
    }
}

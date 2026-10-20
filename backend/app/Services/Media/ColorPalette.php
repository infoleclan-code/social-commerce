<?php

declare(strict_types=1);

namespace App\Services\Media;

/** Names the dominant hues in a photo. Used to reject a color that the catalog item does not have. */
final class ColorPalette
{
    /** @var list<string> */
    public const NAMES = ['red', 'orange', 'yellow', 'green', 'blue', 'purple', 'grey', 'black', 'white'];

    /** @return list<string> */
    public static function dominant(string $bytes): array
    {
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return [];
        }
        $width = imagesx($image);
        $height = imagesy($image);
        $counts = array_fill_keys(self::NAMES, 0);
        $samples = 0;
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $px = min($width - 1, (int) (($x + 0.5) * $width / 8));
                $py = min($height - 1, (int) (($y + 0.5) * $height / 8));
                $rgb = imagecolorat($image, $px, $py);
                $name = self::name((($rgb >> 16) & 255) / 255, (($rgb >> 8) & 255) / 255, ($rgb & 255) / 255);
                $counts[$name]++;
                $samples++;
            }
        }
        imagedestroy($image);
        arsort($counts);
        $colors = [];
        foreach ($counts as $name => $count) {
            if ($count < max(2, (int) ceil($samples * 0.12))) {
                continue;
            }
            $colors[] = $name;
            if (count($colors) === 3) {
                break;
            }
        }

        return $colors;
    }

    public static function name(float $red, float $green, float $blue): string
    {
        $max = max($red, $green, $blue);
        $min = min($red, $green, $blue);
        $delta = $max - $min;
        if ($max < 0.12) {
            return 'black';
        }
        if ($delta < 0.12) {
            return $max > 0.82 ? 'white' : 'grey';
        }
        $hue = match (true) {
            $max === $red => 60 * fmod((($green - $blue) / $delta), 6),
            $max === $green => 60 * ((($blue - $red) / $delta) + 2),
            default => 60 * ((($red - $green) / $delta) + 4),
        };
        if ($hue < 0) {
            $hue += 360;
        }

        return match (true) {
            $hue < 18 || $hue >= 345 => 'red',
            $hue < 45 => 'orange',
            $hue < 70 => 'yellow',
            $hue < 170 => 'green',
            $hue < 255 => 'blue',
            default => 'purple',
        };
    }

    /** @param  list<string>  $scan
     * @param  list<string>  $product
     */
    public static function agrees(array $scan, array $product): bool
    {
        $shared = array_intersect($scan, $product);
        if ($shared === []) {
            return false;
        }
        $chromatic = array_diff($shared, ['grey', 'black', 'white']);

        return $chromatic !== [] || count($shared) >= 2;
    }

    /** @param  list<string>  $colors */
    public static function literal(array $colors): string
    {
        $safe = array_values(array_intersect($colors, self::NAMES));

        return '{'.implode(',', $safe).'}';
    }

    /** @return list<string> */
    public static function parse(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_intersect($value, self::NAMES));
        }
        if (! is_string($value) || $value === '' || $value === '{}') {
            return [];
        }
        $trimmed = trim($value, '{}');

        return $trimmed === '' ? [] : array_values(array_intersect(str_getcsv($trimmed), self::NAMES));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Support;

/** Hand-built hostile files for upload tests. Nothing here is decoded by the test itself. */
final class HostileImages
{
    /** A 1 kB PNG whose header declares $width x $height pixels (a decompression bomb). */
    public static function pngBomb(int $width = 30000, int $height = 30000): string
    {
        $ihdr = pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);
        $idat = (string) gzcompress(str_repeat("\0", 1024), 9);

        return "\x89PNG\r\n\x1A\n".self::chunk('IHDR', $ihdr).self::chunk('IDAT', $idat).self::chunk('IEND', '');
    }

    /** A correct 64x64 PNG header followed by pixel data that is not zlib at all. */
    public static function corruptPng(): string
    {
        $ihdr = pack('NNCCCCC', 64, 64, 8, 2, 0, 0, 0);

        return "\x89PNG\r\n\x1A\n".self::chunk('IHDR', $ihdr).self::chunk('IDAT', str_repeat("\xAB", 300)).self::chunk('IEND', '');
    }

    /** A real JPEG with an HTML/JS payload appended after the end-of-image marker. */
    public static function polyglot(string $jpeg): string
    {
        return $jpeg.'<html><script>alert(document.cookie)</script></html>';
    }

    /** PNG bytes renamed with a JPEG magic prefix (type confusion). */
    public static function mislabelled(string $png): string
    {
        return "\xFF\xD8\xFF\xE0".substr($png, 4);
    }

    public static function svg(): string
    {
        return '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><rect width="100" height="100"/></svg>';
    }

    public static function gif(): string
    {
        $image = imagecreatetruecolor(64, 64);
        ob_start();
        imagegif($image);

        return (string) ob_get_clean();
    }

    public static function png(int $width = 64, int $height = 64, bool $flat = false): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 30, 120, 200));
        if (! $flat) {
            imagefilledellipse($image, intdiv($width, 2), intdiv($height, 2), intdiv($width, 2), intdiv($height, 3), imagecolorallocate($image, 250, 220, 10));
            imagefilledrectangle($image, 2, 2, intdiv($width, 4), intdiv($height, 5), imagecolorallocate($image, 10, 10, 10));
        }
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    public static function webp(string $jpeg): string
    {
        $image = imagecreatefromstring($jpeg);
        ob_start();
        imagewebp($image, null, 80);

        return (string) ob_get_clean();
    }

    public static function flatJpeg(int $red = 120, int $green = 30, int $blue = 30): string
    {
        $image = imagecreatetruecolor(256, 256);
        imagefill($image, 0, 0, imagecolorallocate($image, $red, $green, $blue));
        ob_start();
        imagejpeg($image, null, 90);

        return (string) ob_get_clean();
    }

    /** Re-encodes a JPEG: resized, recompressed, optionally brightened (a re-uploaded copy). */
    public static function variant(string $jpeg, int $width, int $quality, int $brightness = 0): string
    {
        $source = imagecreatefromstring($jpeg);
        $height = (int) round($width * imagesy($source) / imagesx($source));
        $target = imagecreatetruecolor($width, $height);
        imagecopyresampled($target, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));
        if ($brightness !== 0) {
            imagefilter($target, IMG_FILTER_BRIGHTNESS, $brightness);
        }
        ob_start();
        imagejpeg($target, null, $quality);

        return (string) ob_get_clean();
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }
}

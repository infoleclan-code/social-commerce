<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Exceptions\ApiException;
use GdImage;

/**
 * A validated, decoded, upright image (EXIF orientation applied).
 *
 * Decoding is the trust boundary for reel uploads: only JPEG, PNG and WebP are accepted, the
 * declared size is checked before allocating pixels, and re-encoding through toJpeg() drops
 * EXIF, GPS, and camera metadata.
 */
final class DecodedImage
{
    private const ALLOWED = [IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_PNG => 'image/png', IMAGETYPE_WEBP => 'image/webp'];

    private function __construct(
        public readonly GdImage $gd,
        public readonly int $width,
        public readonly int $height,
        public readonly string $mime,
        public readonly string $sha256,
    ) {}

    public static function fromBytes(string $bytes, int $maxPixels): self
    {
        $info = @getimagesizefromstring($bytes);
        if ($info === false || ! isset(self::ALLOWED[$info[2]])) {
            throw ApiException::mediaRejected('Upload a JPEG, PNG or WebP image.');
        }
        [$width, $height, $type] = $info;
        if ($width < 32 || $height < 32) {
            throw ApiException::mediaRejected('The image is too small (minimum 32x32 pixels).');
        }
        if ($width * $height > $maxPixels) {
            throw ApiException::mediaRejected('The image is too large to process.');
        }

        $gd = @imagecreatefromstring($bytes);
        if (! $gd instanceof GdImage) {
            throw ApiException::mediaRejected('The image could not be decoded.');
        }
        if (! imageistruecolor($gd)) {
            imagepalettetotruecolor($gd);
        }
        imagealphablending($gd, true);

        if ($type === IMAGETYPE_JPEG) {
            $gd = self::applyExifOrientation($gd, $bytes);
        }

        return new self($gd, imagesx($gd), imagesy($gd), self::ALLOWED[$type], hash('sha256', $bytes));
    }

    /** Re-encoded copy for storage: longest edge capped, transparency flattened on white, no metadata. */
    public function toJpeg(int $maxEdge, int $quality = 82): string
    {
        $scale = min(1.0, $maxEdge / max($this->width, $this->height));
        $targetWidth = max(1, (int) round($this->width * $scale));
        $targetHeight = max(1, (int) round($this->height * $scale));

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $this->gd, 0, 0, 0, 0, $targetWidth, $targetHeight, $this->width, $this->height);

        ob_start();
        imagejpeg($canvas, null, $quality);

        return (string) ob_get_clean();
    }

    private static function applyExifOrientation(GdImage $gd, string $bytes): GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $gd;
        }
        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($bytes));
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        $rotated = match ($orientation) {
            3, 4 => imagerotate($gd, 180, 0),
            5, 6 => imagerotate($gd, -90, 0),
            7, 8 => imagerotate($gd, 90, 0),
            default => $gd,
        };
        if (! $rotated instanceof GdImage) {
            return $gd;
        }
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($rotated, IMG_FLIP_HORIZONTAL);
        }

        return $rotated;
    }
}

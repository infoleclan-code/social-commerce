<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Exceptions\ApiException;

/**
 * The trust boundary for images people send us to analyze (visual search, auto-tag frames, catalog
 * photos). Nothing is decoded until the bytes pass every cheap check:
 *
 *  - size: at most commerce.visual_search.max_upload_bytes (base64 is length-checked before decoding);
 *  - integrity: when the app sends image_sha256, it must match the bytes (truncated or tampered
 *    uploads are refused, IMAGE_HASH_MISMATCH);
 *  - type: the magic bytes must be JPEG, PNG or WebP, and the parsed header must agree (a PNG named
 *    .jpg, an SVG, a GIF or an HTML polyglot is refused);
 *  - dimensions: read from the header before allocating pixels; at most
 *    commerce.visual_search.max_pixels, so a 50 kB "decompression bomb" declaring 30000x30000 pixels
 *    never reaches the decoder.
 * Decoding then goes through DecodedImage (EXIF orientation, no metadata kept).
 */
final class ImageGuard
{
    private const MAX_EDGE = 12_000;

    public static function decode(string $bytes, ?string $claimedSha256 = null): DecodedImage
    {
        $maxBytes = (int) config('commerce.visual_search.max_upload_bytes', 8 * 1024 * 1024);
        $maxPixels = (int) config('commerce.visual_search.max_pixels', 16_000_000);
        if ($bytes === '') {
            throw ApiException::imageInvalid('Send an image.');
        }
        if (strlen($bytes) > $maxBytes) {
            throw ApiException::imageInvalid('The image is larger than '.intdiv($maxBytes, 1024 * 1024).' MB.');
        }
        if ($claimedSha256 !== null && $claimedSha256 !== '') {
            if (preg_match('/^[A-Fa-f0-9]{64}$/', $claimedSha256) !== 1 || ! hash_equals(hash('sha256', $bytes), strtolower($claimedSha256))) {
                throw ApiException::imageHashMismatch();
            }
        }
        $kind = self::sniff($bytes);
        if ($kind === null) {
            throw ApiException::imageInvalid('Use a JPEG, PNG or WebP image.');
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            throw ApiException::imageInvalid('The image could not be read.');
        }
        if ($info[2] !== $kind) {
            throw ApiException::imageInvalid('The file type does not match its contents.');
        }
        [$width, $height] = $info;
        if ($width < 32 || $height < 32) {
            throw ApiException::imageInvalid('The image is too small (minimum 32x32 pixels).');
        }
        if ($width > self::MAX_EDGE || $height > self::MAX_EDGE || $width * $height > $maxPixels) {
            throw ApiException::imageInvalid('The image has too many pixels to analyze.');
        }
        try {
            return DecodedImage::fromBytes($bytes, $maxPixels);
        } catch (ApiException $rejected) {
            throw ApiException::imageInvalid($rejected->getMessage());
        }
    }

    /** Decodes base64 (optionally a data: URL of an allowed type) after a length check. */
    public static function decodeBase64(string $encoded, ?string $claimedSha256 = null): DecodedImage
    {
        if (preg_match('#^data:image/(jpeg|png|webp);base64,#', $encoded, $prefix) === 1) {
            $encoded = substr($encoded, strlen($prefix[0]));
        } elseif (str_starts_with($encoded, 'data:')) {
            throw ApiException::imageInvalid('Use a JPEG, PNG or WebP image.');
        }
        $maxBytes = (int) config('commerce.visual_search.max_upload_bytes', 8 * 1024 * 1024);
        if (strlen($encoded) > intdiv($maxBytes + 2, 3) * 4 + 4) {
            throw ApiException::imageInvalid('The image is larger than '.intdiv($maxBytes, 1024 * 1024).' MB.');
        }
        $bytes = base64_decode($encoded, true);
        if (! is_string($bytes)) {
            throw ApiException::imageInvalid('The image is not valid base64.');
        }

        return self::decode($bytes, $claimedSha256);
    }

    /** IMAGETYPE_* from the magic bytes, or null. */
    public static function sniff(string $bytes): ?int
    {
        if (strlen($bytes) < 12) {
            return null;
        }

        return match (true) {
            str_starts_with($bytes, "\xFF\xD8\xFF") => IMAGETYPE_JPEG,
            str_starts_with($bytes, "\x89PNG\r\n\x1A\n") => IMAGETYPE_PNG,
            str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => IMAGETYPE_WEBP,
            default => null,
        };
    }
}

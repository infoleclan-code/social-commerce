<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;

/**
 * Turns storage keys (what the database stores) into URLs the app can load.
 * With a CDN configured (feed.cdn_url) URLs point at the CDN; otherwise at the disk's own URL.
 */
final readonly class MediaUrl
{
    public function __construct(private FilesystemFactory $filesystems) {}

    public function for(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $path) === 1) {
            return $path;   // already absolute (e.g. merchant image URLs)
        }

        $cdn = rtrim((string) config('feed.cdn_url'), '/');
        if ($cdn !== '') {
            return $cdn.'/'.implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));
        }

        return $this->filesystems->disk((string) config('feed.media_disk'))->url($path);
    }
}

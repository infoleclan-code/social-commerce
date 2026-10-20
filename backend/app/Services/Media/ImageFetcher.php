<?php

declare(strict_types=1);

namespace App\Services\Media;

/** Downloads a catalog photo. Implementations must refuse anything but a public https image. */
interface ImageFetcher
{
    /** @throws ImageFetchFailed */
    public function fetch(string $url): string;
}

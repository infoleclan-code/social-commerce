<?php

declare(strict_types=1);

namespace App\Services\Vision;

/** What a scanned photo is, in terms the catalog can confirm or reject. */
interface VisionDescriber
{
    /**
     * @return array{category: ?string, colors: list<string>, brand: ?string, features: list<string>, confidence: float, source: string}
     */
    public function describe(string $bytes): array;
}

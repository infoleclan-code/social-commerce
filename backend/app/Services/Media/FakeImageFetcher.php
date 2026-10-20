<?php

declare(strict_types=1);

namespace App\Services\Media;

/** Test double: answers configured URLs, fails everything else like an unreachable host. */
final class FakeImageFetcher implements ImageFetcher
{
    /** @var array<string, string> */
    public array $responses = [];

    /** @var list<string> */
    public array $requested = [];

    public function respond(string $url, string $bytes): void
    {
        $this->responses[$url] = $bytes;
    }

    public function fetch(string $url): string
    {
        $this->requested[] = $url;
        (new SafeImageFetcherRules)->assertUrl($url);

        return $this->responses[$url] ?? throw new ImageFetchFailed('Unreachable in tests: '.$url);
    }
}

<?php

declare(strict_types=1);

namespace App\Support;

/** Hashtags stored on feed_posts.tags: lowercase, no leading #, letters, numbers, underscore. */
final class Hashtag
{
    public static function normalize(string $raw): ?string
    {
        $tag = mb_strtolower(ltrim(trim($raw), '#'));
        if (preg_match('/^[\p{L}\p{N}_]{1,40}$/u', $tag) !== 1) {
            return null;
        }

        return $tag;
    }
}

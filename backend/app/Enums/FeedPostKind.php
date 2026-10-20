<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Mirrors the CHECK constraint on feed_posts.kind.
 * Keep values identical to the database; tests/Feature/SchemaParityTest.php compares them.
 */
enum FeedPostKind: string
{
    /** Photo or video reel. */
    case Media = 'media';
}

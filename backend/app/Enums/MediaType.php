<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Mirrors the CHECK constraint on feed_posts.media_type
 * Keep values identical to the database; tests/Feature/SchemaParityTest.php compares them.
 */
enum MediaType: string
{
    case Image = 'image';
    case Video = 'video';
}

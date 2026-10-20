<?php

declare(strict_types=1);

namespace App\Services\Feed;

use App\Models\FeedPost;
use Illuminate\Support\Collection;

final readonly class FeedPage
{
    /** @param  Collection<int, FeedPost>  $posts */
    public function __construct(
        public Collection $posts,
        public ?string $nextCursor,
    ) {}
}

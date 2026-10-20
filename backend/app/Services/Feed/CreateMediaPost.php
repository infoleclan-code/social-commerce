<?php

declare(strict_types=1);

namespace App\Services\Feed;

use App\Enums\FeedAudience;
use Illuminate\Http\UploadedFile;

/** Validated input for a new Reels-style post (built by StorePostRequest). */
final readonly class CreateMediaPost
{
    /** @param  list<string>  $tags */
    public function __construct(
        public UploadedFile $media,
        public ?UploadedFile $thumbnail,
        public ?string $caption,
        public array $tags,
        public FeedAudience $audience,
        public ?int $clientDurationMs,
    ) {}
}

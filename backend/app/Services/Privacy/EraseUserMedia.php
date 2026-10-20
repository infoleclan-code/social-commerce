<?php

declare(strict_types=1);

namespace App\Services\Privacy;

use App\Models\FeedPost;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;

/**
 * Account erasure, step 1: delete the user's reel media and avatar from object storage.
 * Run this before `SELECT anonymize_user(?)`, which then deletes the rows and scrubs identity.
 */
final readonly class EraseUserMedia
{
    public function __construct(private FilesystemFactory $filesystems) {}

    public function handle(User $user): void
    {
        $media = $this->filesystems->disk((string) config('feed.media_disk'));
        FeedPost::withTrashed()
            ->where('user_id', $user->getKey())
            ->where(fn ($query) => $query->whereNotNull('media_path')->orWhereNotNull('thumbnail_path'))
            ->select(['id', 'media_path', 'thumbnail_path'])
            ->chunkById(500, function ($posts) use ($media): void {
                $media->delete(array_values(array_filter([...$posts->pluck('media_path'), ...$posts->pluck('thumbnail_path')])));
            });

        $media->deleteDirectory("posts/{$user->getKey()}");

        if ($user->avatar_path !== null) {
            $media->delete($user->avatar_path);
        }
    }
}

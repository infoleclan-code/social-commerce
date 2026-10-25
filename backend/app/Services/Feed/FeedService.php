<?php

declare(strict_types=1);

namespace App\Services\Feed;

use App\Enums\FeedAudience;
use App\Enums\FeedPostKind;
use App\Enums\MediaType;
use App\Enums\ShareChannel;
use App\Exceptions\ApiException;
use App\Models\FeedPost;
use App\Models\User;
use App\Services\Commerce\AutoTagService;
use App\Services\Commerce\RewardService;
use App\Services\Commerce\ShareEarnService;
use App\Services\Media\Contracts\VideoProcessor;
use App\Services\Media\DecodedImage;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * The Reels-style feed: reading pages, posting media, likes and shares.
 *
 * Reading
 *  - "for_you": public media posts by rank_score (engagement + recency, maintained by a DB trigger).
 *    The ids of the first pages are cached for a few seconds and shared by every viewer, then
 *    filtered per viewer (blocks) and hydrated with the viewer's own like flag.
 *  - "following": media posts by the viewer's friends and the viewer, newest first.
 *  Both use keyset cursors, eager-load the author, and never expose who liked a post.
 *
 * Writing
 *  - media is re-encoded (images) or re-muxed (videos) so no location metadata is ever stored, then
 *    written to the media disk under posts/{user}/{post}.*; the DB row stores keys only;
 *  - like/unlike are single idempotent statements; DB triggers keep the counters exact.
 */
final readonly class FeedService
{
    public function __construct(
        private ConnectionInterface $db,
        private Cache $cache,
        private FilesystemFactory $filesystems,
        private VideoProcessor $videos,
        private RewardService $rewards,
        private ShareEarnService $shares,
        private TasteService $taste,
        private AutoTagService $autoTags,
        private ForYouCandidates $candidates,
    ) {}

    // ---------------------------------------------------------------- reading

    public function page(User $viewer, string $mode, ?string $cursor, int $limit): FeedPage
    {
        return $mode === 'following'
            ? $this->following($viewer, $cursor === null ? null : FeedCursor::decode($cursor), $limit)
            : $this->forYou($viewer, $cursor === null ? null : FeedCursor::decode($cursor), $limit);
    }

    private function forYou(User $viewer, ?FeedCursor $cursor, int $limit): FeedPage
    {
        if ($cursor !== null && $cursor->score === null) {
            throw ApiException::invalidCursor();
        }
        $head = $this->forYouHead($viewer, $cursor, $limit);
        if ($head !== null) {
            return $head;
        }
        $viewerId = $viewer->getKey();
        $score = TasteService::scoreSql();
        $query = FeedPost::query()
            ->selectRaw("feed_posts.*, ({$score}) AS personal_score", [$viewerId])
            ->where('feed_posts.kind', FeedPostKind::Media->value)
            ->where('feed_posts.audience', FeedAudience::Public->value)
            ->whereNull('feed_posts.deleted_at')
            ->whereNull('feed_posts.hidden_at')
            ->when($cursor !== null, function (Builder $after) use ($score, $viewerId, $cursor): void {
                $after->whereRaw(
                    "({$score}) < ?::double precision OR (({$score}) = ?::double precision AND feed_posts.id < ?)",
                    [$viewerId, $cursor->score, $viewerId, $cursor->score, $cursor->id],
                );
            })
            ->orderByRaw("({$score}) DESC", [$viewerId])
            ->orderByDesc('feed_posts.id');

        $posts = $this->hydrate($query, $viewer, $limit + 1);
        $page = $posts->take($limit)->values();
        $last = $page->last();

        return new FeedPage(
            $page,
            $posts->count() > $limit && $last !== null
                ? FeedCursor::forRank((string) $last->getRawOriginal('personal_score'), $last->id)
                : null,
        );
    }

    /**
     * The first pages of For You from the cached candidate ranking (ForYouCandidates). Returns null when
     * the cursor is past the cached head, or when hydration filtered out too much to fill a page; the
     * caller then uses the SQL path, which continues from the same (score, id) cursor.
     */
    private function forYouHead(User $viewer, ?FeedCursor $cursor, int $limit): ?FeedPage
    {
        $ranked = array_slice($this->candidates->rankFor((string) $viewer->getKey()), 0, ForYouCandidates::CACHED_HEAD);
        if ($cursor !== null) {
            $score = (float) $cursor->score;
            $ranked = array_values(array_filter($ranked, fn (array $row): bool => $row['score'] < $score
                || ($row['score'] == $score && strcmp($row['id'], $cursor->id) < 0)));
        }
        if (count($ranked) < $limit + 1) {
            return null;
        }
        $scores = array_column($ranked, 'score', 'id');
        $position = array_flip(array_keys($scores));
        $posts = $this->hydrate(
            FeedPost::query()
                ->whereIn('feed_posts.id', array_keys($scores))
                ->where('feed_posts.kind', FeedPostKind::Media->value)
                ->where('feed_posts.audience', FeedAudience::Public->value)
                ->whereNull('feed_posts.deleted_at')
                ->whereNull('feed_posts.hidden_at'),
            $viewer,
        )->sortBy(fn (FeedPost $post): int => $position[$post->id])->values();
        if ($posts->count() < $limit + 1) {
            return null;
        }
        $page = $posts->take($limit)->values();
        $last = $page->last();

        return new FeedPage($page, FeedCursor::forRank((string) json_encode($scores[$last->id], JSON_PRESERVE_ZERO_FRACTION), $last->id));
    }

    private function following(User $viewer, ?FeedCursor $cursor, int $limit): FeedPage
    {
        if ($cursor !== null && $cursor->time === null) {
            throw ApiException::invalidCursor();
        }
        $viewerId = $viewer->getKey();

        $query = FeedPost::query()
            ->where('feed_posts.kind', FeedPostKind::Media->value)
            ->visibleTo($viewer)
            ->where(function (Builder $authors) use ($viewerId): void {
                $authors->where('feed_posts.user_id', $viewerId)->orWhereIn('feed_posts.user_id', function ($friends) use ($viewerId): void {
                    $friends->selectRaw('CASE WHEN requester_id = ? THEN addressee_id ELSE requester_id END', [$viewerId])
                        ->from('social_connections')
                        ->where('status', 'accepted')
                        ->where(fn ($pair) => $pair->where('requester_id', $viewerId)->orWhere('addressee_id', $viewerId));
                });
            })
            ->when($cursor !== null, function (Builder $after) use ($cursor): void {
                $time = $cursor->time->format('Y-m-d H:i:s.uP');
                $after->where(fn (Builder $older) => $older->where('feed_posts.created_at', '<', $time)
                    ->orWhere(fn (Builder $tie) => $tie->where('feed_posts.created_at', '=', $time)->where('feed_posts.id', '<', $cursor->id)));
            })
            ->orderByDesc('feed_posts.created_at')
            ->orderByDesc('feed_posts.id');

        $posts = $this->hydrate($query, $viewer, $limit + 1);
        $page = $posts->take($limit)->values();
        $last = $page->last();

        return new FeedPage(
            $page,
            $posts->count() > $limit && $last !== null ? FeedCursor::forTime(CarbonImmutable::instance($last->created_at), $last->id) : null,
        );
    }

    public function postsForTag(User $viewer, string $tag, ?string $cursor, int $limit): FeedPage
    {
        $position = $cursor === null ? null : FeedCursor::decode($cursor);
        if ($position !== null && $position->score === null) {
            throw ApiException::invalidCursor();
        }

        $query = FeedPost::query()
            ->where('feed_posts.kind', FeedPostKind::Media->value)
            ->whereRaw('feed_posts.tags @> ?::jsonb', [json_encode([$tag], JSON_THROW_ON_ERROR)])
            ->visibleTo($viewer)
            ->when($position !== null, function (Builder $after) use ($position): void {
                $after->where(function (Builder $page) use ($position): void {
                    $page->whereRaw('feed_posts.rank_score < ?::double precision', [$position->score])
                        ->orWhere(fn (Builder $tie) => $tie->whereRaw('feed_posts.rank_score = ?::double precision', [$position->score])->where('feed_posts.id', '<', $position->id));
                });
            })
            ->orderByDesc('feed_posts.rank_score')
            ->orderByDesc('feed_posts.id');

        $posts = $this->hydrate($query, $viewer, $limit + 1);
        $page = $posts->take($limit)->values();
        $last = $page->last();

        return new FeedPage(
            $page,
            $posts->count() > $limit && $last !== null ? FeedCursor::forRank((string) $last->getRawOriginal('rank_score'), $last->id) : null,
        );
    }

    /** @return Builder<FeedPost> public media posts in "For You" order */
    private function rankedPublic(): Builder
    {
        return FeedPost::query()
            ->where('feed_posts.kind', FeedPostKind::Media->value)
            ->where('feed_posts.audience', FeedAudience::Public->value)
            ->orderByDesc('feed_posts.rank_score')
            ->orderByDesc('feed_posts.id');
    }

    /**
     * @param  Builder<FeedPost>  $query
     * @return Collection<int, FeedPost>
     */
    private function hydrate(Builder $query, User $viewer, ?int $limit = null): Collection
    {
        return $query
            ->whereNull('feed_posts.hidden_at')
            ->withoutBlockedFor($viewer)
            ->withViewerState($viewer)
            ->with(['user:id,username,display_name,avatar_path', 'shopTag.product.liveDrops'])
            ->when($limit !== null, fn (Builder $limited) => $limited->limit($limit))
            ->get();
    }

    public function visiblePost(User $viewer, string $postId): FeedPost
    {
        if (! Str::isUuid($postId)) {
            throw ApiException::postNotFound();
        }

        return FeedPost::query()->visibleTo($viewer)->whereKey($postId)->first() ?? throw ApiException::postNotFound();
    }

    // ---------------------------------------------------------------- writing

    public function createMediaPost(User $author, CreateMediaPost $input): FeedPost
    {
        if (! $author->social_feed_enabled) {
            throw ApiException::socialOptInRequired();
        }

        $postId = (string) Str::uuid7();
        $disk = $this->filesystems->disk((string) config('feed.media_disk'));
        $prefix = "posts/{$author->getKey()}/{$postId}";
        $stored = [];
        $temporary = [];
        $frameBytes = null;

        try {
            $isVideo = str_starts_with((string) $input->media->getMimeType(), 'video/');
            if ($isVideo) {
                $video = $this->videos->process($input->media->getRealPath(), $input->clientDurationMs);
                if ($video->path !== $input->media->getRealPath()) {
                    $temporary[] = $video->path;
                }
                if ($video->posterPath !== null) {
                    $temporary[] = $video->posterPath;
                }
                if ($video->durationMs !== null && $video->durationMs > (int) config('feed.video.max_duration_ms')) {
                    throw ApiException::mediaRejected('Videos can be up to '.intdiv((int) config('feed.video.max_duration_ms'), 1000).' seconds long.');
                }

                $posterBytes = $input->thumbnail !== null
                    ? (string) file_get_contents($input->thumbnail->getRealPath())
                    : ($video->posterPath !== null ? (string) file_get_contents($video->posterPath) : null);
                if ($posterBytes === null) {
                    throw ApiException::mediaRejected('Add a cover image for the video.');
                }
                $frameBytes = $posterBytes;
                $poster = DecodedImage::fromBytes($posterBytes, 40_000_000);

                $mediaPath = "{$prefix}.mp4";
                $thumbnailPath = "{$prefix}_poster.jpg";
                $this->putFile($disk, $mediaPath, $video->path, 'video/mp4');
                $stored[] = $mediaPath;
                $disk->put($thumbnailPath, $poster->toJpeg(1080, 82), ['visibility' => 'public', 'ContentType' => 'image/jpeg']);
                $stored[] = $thumbnailPath;

                $attributes = [
                    'media_type' => MediaType::Video,
                    'media_width' => $video->width ?? $poster->width,
                    'media_height' => $video->height ?? $poster->height,
                    'media_duration_ms' => $video->durationMs,
                ];
            } else {
                $image = DecodedImage::fromBytes((string) file_get_contents($input->media->getRealPath()), 40_000_000);
                $bytes = $image->toJpeg((int) config('feed.image.max_edge'), 85);
                $stored[] = $mediaPath = "{$prefix}.jpg";
                $disk->put($mediaPath, $bytes, ['visibility' => 'public', 'ContentType' => 'image/jpeg']);
                $scaled = getimagesizefromstring($bytes) ?: [$image->width, $image->height];
                $thumbnailPath = null;

                $attributes = [
                    'media_type' => MediaType::Image,
                    'media_width' => $scaled[0],
                    'media_height' => $scaled[1],
                    'media_duration_ms' => null,
                ];
            }

            $post = (new FeedPost([
                'caption' => $input->caption,
                'audience' => $input->audience,
                'tags' => $input->tags,
            ]))->forceFill($attributes + [
                'id' => $postId,
                'user_id' => $author->getKey(),
                'kind' => FeedPostKind::Media,
                'media_path' => $mediaPath,
                'thumbnail_path' => $thumbnailPath,
            ]);

            try {
                $this->db->transaction(fn () => $post->save());   // savepoint when nested
            } catch (QueryException $exception) {
                if (str_contains($exception->getMessage(), 'has not opted in to the social feed')) {
                    throw ApiException::socialOptInRequired();
                }
                throw $exception;
            }
            if (is_string($frameBytes) && $frameBytes !== '') {
                try {
                    $this->autoTags->match($postId, $frameBytes);
                } catch (ApiException) {
                    // A frame that cannot be read does not block the post.
                }
            }
        } catch (Throwable $failure) {
            $disk->delete($stored);   // never leave orphaned media behind a failed post
            throw $failure;
        } finally {
            foreach ($temporary as $file) {
                @unlink($file);
            }
        }

        return $post->load(['user:id,username,display_name,avatar_path']);
    }

    public function deletePost(User $author, string $postId): void
    {
        $post = FeedPost::query()->whereKey($postId)->where('user_id', $author->getKey())->first()
            ?? throw ApiException::postNotFound();
        $post->delete();   // soft delete; media is removed by the retention job / erasure
    }

    /** @return array{liked: bool, likes_count: int} */
    public function like(User $viewer, string $postId): array
    {
        $post = $this->visiblePost($viewer, $postId);
        $this->db->table('feed_post_likes')->insertOrIgnore([
            'post_id' => $post->id,
            'user_id' => $viewer->getKey(),
            'created_at' => now(),
        ]);
        $this->rewards->grant($viewer, 'like', $post->id, 5, 2, 0);
        $this->taste->observe($viewer, $post->id, 'tap');

        return ['liked' => true, 'likes_count' => $this->likesCount($post->id)];
    }

    /** @return array{liked: bool, likes_count: int} */
    public function unlike(User $viewer, string $postId): array
    {
        $post = $this->visiblePost($viewer, $postId);
        $this->db->table('feed_post_likes')->where(['post_id' => $post->id, 'user_id' => $viewer->getKey()])->delete();

        return ['liked' => false, 'likes_count' => $this->likesCount($post->id)];
    }

    /**
     * Counts the share and returns the public reel link. The channel is recorded by the client
     * only; visibility is unchanged.
     *
     * @return array{shares_count: int, url: string}
     */
    public function share(User $viewer, string $postId, ShareChannel $_channel): array
    {
        $post = $this->visiblePost($viewer, $postId);
        $base = rtrim((string) config('feed.share_base_url'), '/');

        return $this->db->transaction(function () use ($post, $base, $viewer): array {
            $this->db->table('feed_posts')->where('id', $post->id)->increment('shares_count');

            $tracked = $this->shares->mint($viewer, $post->id);

            return [
                'shares_count' => (int) $this->db->table('feed_posts')->where('id', $post->id)->value('shares_count'),
                'url' => $tracked['url'] ?? "{$base}/p/{$post->id}",
                'commission_bps' => $tracked['commission_bps'] ?? 0,
                'token' => $tracked['token'] ?? null,
            ];
        });
    }

    /** @return array{liked: bool, likes_count: int, comments_count: int, shares_count: int} */
    public function engagement(User $viewer, string $postId): array
    {
        $post = $this->visiblePost($viewer, $postId);
        $liked = $this->db->table('feed_post_likes')
            ->where('post_id', $post->id)
            ->where('user_id', $viewer->getKey())
            ->exists();

        return [
            'liked' => $liked,
            'likes_count' => (int) $post->likes_count,
            'comments_count' => (int) $post->comments_count,
            'shares_count' => (int) $post->shares_count,
        ];
    }

    public function commentsCount(string $postId): int
    {
        return (int) $this->db->table('feed_posts')->where('id', $postId)->value('comments_count');
    }

    private function likesCount(string $postId): int
    {
        return (int) $this->db->table('feed_posts')->where('id', $postId)->value('likes_count');
    }

    private function putFile(Filesystem $disk, string $path, string $localPath, string $contentType): void
    {
        $stream = fopen($localPath, 'rb');
        if ($stream === false) {
            throw ApiException::mediaRejected('The upload could not be read.');
        }
        try {
            $disk->writeStream($path, $stream, ['visibility' => 'public', 'ContentType' => $contentType]);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}

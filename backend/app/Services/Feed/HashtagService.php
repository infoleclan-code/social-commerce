<?php

declare(strict_types=1);

namespace App\Services\Feed;

use App\Models\User;
use App\Support\Hashtag;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Hashtag search and trending topics, counted from public reels.
 * The reel list itself still applies visibility and blocks.
 */
final class HashtagService
{
    public function __construct(private readonly FeedService $feed) {}
    /** @return list<array{tag: string, posts: int}> */
    public function search(string $rawQuery): array
    {
        $query = Hashtag::normalize($rawQuery);
        if ($query === null) {
            return [];
        }

        $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query).'%';

        $rows = DB::select(
            <<<'SQL'
            SELECT tag, COUNT(*)::int AS posts
              FROM feed_posts
              CROSS JOIN LATERAL jsonb_array_elements_text(tags) AS tag
             WHERE kind = 'media'
               AND audience = 'public'
               AND deleted_at IS NULL
               AND tag ILIKE ? ESCAPE '\'
             GROUP BY tag
             ORDER BY (tag = ?) DESC, posts DESC, tag ASC
             LIMIT ?
            SQL,
            [$like, $query, (int) config('feed.hashtags.search_limit')],
        );

        return $this->topics($rows);
    }

    /** @return list<array{tag: string, posts: int}> */
    public function trending(): array
    {
        $days = (int) config('feed.hashtags.trending_days');
        $limit = (int) config('feed.hashtags.trending_limit');

        /** @var list<array{tag: string, posts: int}> $topics */
        $topics = Cache::remember('hashtags:trending:v1', 60, function () use ($days, $limit): array {
            $rows = DB::select(
                <<<'SQL'
                SELECT tag,
                       COUNT(*)::int AS posts
                  FROM feed_posts
                  CROSS JOIN LATERAL jsonb_array_elements_text(tags) AS tag
                 WHERE kind = 'media'
                   AND audience = 'public'
                   AND deleted_at IS NULL
                   AND created_at >= now() - make_interval(days => ?)
                 GROUP BY tag
                 ORDER BY SUM(likes_count + 2 * comments_count + 3 * shares_count) DESC, posts DESC, tag ASC
                 LIMIT ?
                SQL,
                [$days, $limit],
            );

            return $this->topics($rows);
        });

        return $topics;
    }

    public function posts(User $viewer, string $tag, ?string $cursor, int $limit): FeedPage
    {
        return $this->feed->postsForTag($viewer, $tag, $cursor, $limit);
    }

    /**
     * @param  list<object>  $rows
     * @return list<array{tag: string, posts: int}>
     */
    private function topics(array $rows): array
    {
        return array_map(static fn (object $row): array => [
            'tag' => (string) $row->tag,
            'posts' => (int) $row->posts,
        ], $rows);
    }
}

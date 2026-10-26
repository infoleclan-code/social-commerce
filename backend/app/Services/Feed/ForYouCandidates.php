<?php

declare(strict_types=1);

namespace App\Services\Feed;

use App\Support\SingleFlightCache;
use Illuminate\Support\Facades\DB;

/**
 * Candidate generation for "For You", cached in Redis, so the head of the feed is no longer a scan of
 * every public post with a correlated taste sub-query per row.
 *
 *  - global pool: the top commerce.cache.feed_pool_size public posts by rank_score (one shared key);
 *  - tag pools: the top commerce.cache.feed_tag_pool_size posts for each of the viewer's five
 *    strongest tags (shared by every viewer with that tag; served by the jsonb GIN index);
 *  - viewer weights: the viewer's tag weights, cached for a minute and dropped when they change.
 * Pools hold ids, scores and tags only (no visibility decisions): every page is hydrated from
 * PostgreSQL with the audience, deletion and block filters, so a cached id can never leak a post.
 * The personal score is computed exactly as TasteService::scoreSql() does, so cursors stay compatible
 * with the SQL path used past the cached head.
 */
final class ForYouCandidates
{
    public const CACHED_HEAD = 60;

    public function __construct(private readonly SingleFlightCache $cache) {}

    /**
     * The viewer's ranked head: [id, score] best first, ties by id descending.
     *
     * @return list<array{id: string, score: float}>
     */
    public function rankFor(string $viewerId): array
    {
        $weights = $this->weights($viewerId);
        $candidates = [];
        foreach ($this->globalPool() as $post) {
            $candidates[$post['id']] = $post;
        }
        arsort($weights);
        foreach (array_slice(array_keys($weights), 0, 5) as $tag) {
            foreach ($this->tagPool((string) $tag) as $post) {
                $candidates[$post['id']] = $post;
            }
        }
        $ranked = [];
        foreach ($candidates as $post) {
            $bonus = 0;
            foreach (array_unique($post['tags']) as $tag) {
                $bonus += $weights[$tag] ?? 0;
            }
            $ranked[] = ['id' => $post['id'], 'score' => (float) $post['rank_score'] + (float) $bonus];
        }
        usort($ranked, fn (array $a, array $b): int => [$b['score'], $b['id']] <=> [$a['score'], $a['id']]);

        return $ranked;
    }

    /** @return list<array{id: string, rank_score: float, tags: list<string>}> */
    public function globalPool(): array
    {
        return $this->cache->remember(
            'feed:fy:global:v1',
            (int) config('commerce.cache.feed_fresh_seconds', 20),
            (int) config('commerce.cache.feed_stale_seconds', 60),
            fn (): array => $this->load(null, (int) config('commerce.cache.feed_pool_size', 1000)),
        );
    }

    /** @return list<array{id: string, rank_score: float, tags: list<string>}> */
    public function tagPool(string $tag): array
    {
        return $this->cache->remember(
            'feed:fy:tag:v1:'.hash('sha256', $tag),
            (int) config('commerce.cache.feed_fresh_seconds', 20),
            (int) config('commerce.cache.feed_stale_seconds', 60),
            fn (): array => $this->load($tag, (int) config('commerce.cache.feed_tag_pool_size', 200)),
        );
    }

    /** @return array<string, int> */
    public function weights(string $viewerId): array
    {
        return $this->cache->remember('feed:taste:v1:'.$viewerId, 60, 0, fn (): array => DB::table('viewer_tag_weights')
            ->where('user_id', $viewerId)
            ->pluck('weight', 'tag')
            ->map(fn (mixed $weight): int => (int) $weight)
            ->all());
    }

    public function forgetWeights(string $viewerId): void
    {
        $this->cache->forget('feed:taste:v1:'.$viewerId);
    }

    /** @return list<array{id: string, rank_score: float, tags: list<string>}> */
    private function load(?string $tag, int $limit): array
    {
        return DB::table('feed_posts')
            ->where('kind', 'media')
            ->where('audience', 'public')
            ->whereNull('deleted_at')
            ->whereNull('hidden_at')
            ->when($tag !== null, fn ($query) => $query->whereRaw('tags @> ?::jsonb', [json_encode([$tag], JSON_THROW_ON_ERROR)]))
            ->orderByDesc('rank_score')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'rank_score', 'tags'])
            ->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'rank_score' => (float) $row->rank_score,
                'tags' => array_values(array_filter((array) json_decode((string) $row->tags, true), 'is_string')),
            ])
            ->all();
    }
}

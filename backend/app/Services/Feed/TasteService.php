<?php

declare(strict_types=1);

namespace App\Services\Feed;

use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Taste quiz and the per-viewer weights behind For You. */
final class TasteService
{
    /** @var list<string> */
    public const STYLES = ['streetwear', 'sneakers', 'beauty', 'travel', 'home', 'studio'];

    private const WEIGHTS = [
        'quiz' => 12,
        'watch' => 2,
        'tap' => 3,
        'save' => 4,
        'order' => 8,
    ];

    /** @return array{completed: bool, styles: list<string>} */
    public function status(User $user): array
    {
        $styles = DB::table('taste_profiles')->where('user_id', $user->id)->value('styles');

        return [
            'completed' => $styles !== null,
            'styles' => self::STYLES,
            'chosen' => $this->strings($styles),
        ];
    }

    /** @param  list<string>  $styles */
    public function save(User $user, array $styles): array
    {
        $chosen = array_values(array_unique(array_intersect($styles, self::STYLES)));
        if ($chosen === []) {
            throw ApiException::hashtagInvalid();
        }
        DB::statement(
            <<<'SQL'
            INSERT INTO taste_profiles (user_id, styles, completed_at)
            VALUES (?::uuid, ?::text[], now())
            ON CONFLICT (user_id) DO UPDATE SET styles = EXCLUDED.styles, completed_at = now()
            SQL,
            [$user->id, $this->pgArray($chosen)],
        );
        foreach ($chosen as $style) {
            $this->add($user->id, $style, self::WEIGHTS['quiz']);
        }

        return $this->status($user);
    }

    public function observe(User $user, string $postId, string $kind): void
    {
        if (! isset(self::WEIGHTS[$kind]) || $kind === 'quiz') {
            return;
        }
        $tags = DB::table('feed_posts')->where('id', $postId)->value('tags');
        foreach ($this->strings($tags) as $tag) {
            $this->add($user->id, $tag, self::WEIGHTS[$kind]);
        }
    }

    public function saveProduct(User $user, string $productId): void
    {
        $inserted = DB::table('product_saves')->insertOrIgnore([
            'user_id' => $user->id,
            'product_id' => $productId,
            'created_at' => now(),
        ]);
        if ($inserted !== 1) {
            return;
        }
        $postId = DB::table('reel_product_tags')->where('product_id', $productId)->value('post_id');
        if ($postId !== null) {
            $this->observe($user, (string) $postId, 'save');
        }
    }

    /**
     * rank_score plus this viewer's taste and behaviour. Bound once as the viewer id.
     */
    public static function scoreSql(): string
    {
        return <<<'SQL'
feed_posts.rank_score + COALESCE((
  SELECT SUM(v.weight)::double precision
  FROM viewer_tag_weights v
  WHERE v.user_id = ?::uuid
    AND EXISTS (
      SELECT 1
      FROM jsonb_array_elements_text(COALESCE(feed_posts.tags, '[]'::jsonb)) AS tag(value)
      WHERE tag.value = v.tag
    )
), 0)
SQL;
    }

    private function add(string $userId, string $tag, int $weight): void
    {
        DB::statement(
            <<<'SQL'
            INSERT INTO viewer_tag_weights (user_id, tag, weight)
            VALUES (?::uuid, ?, ?)
            ON CONFLICT (user_id, tag) DO UPDATE SET weight = viewer_tag_weights.weight + EXCLUDED.weight
            SQL,
            [$userId, $tag, $weight],
        );
        app(ForYouCandidates::class)->forgetWeights($userId);
    }

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter($value, 'is_string'));
        }
        if (! is_string($value) || $value === '') {
            return [];
        }
        $trimmed = trim($value, '{}');
        if ($trimmed === '') {
            return [];
        }
        if (str_starts_with($value, '[')) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
        }

        return array_map(fn (string $part): string => trim($part, '"'), explode(',', $trimmed));
    }

    /** @param  list<string>  $values */
    private function pgArray(array $values): string
    {
        return '{'.implode(',', $values).'}';
    }
}

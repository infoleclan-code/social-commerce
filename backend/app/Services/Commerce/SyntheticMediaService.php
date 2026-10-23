<?php

declare(strict_types=1);

namespace App\Services\Commerce;

use App\Exceptions\ApiException;
use App\Models\FeedPost;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Synthetic dubs and subtitles. EU AI Act Art. 50: the track is stored only with
 * a fixed "AI-generated" label, and the cues repeat that label so a player cannot
 * show the track without the disclosure. No audio file is invented here.
 */
final class SyntheticMediaService
{
    public const LABEL = 'AI-generated';

    /** @return array<string, mixed> */
    public function publish(User $author, string $postId, string $language, string $kind): array
    {
        $post = FeedPost::query()->whereKey($postId)->where('user_id', $author->getKey())->first()
            ?? throw ApiException::postNotFound();
        $language = strtolower($language);
        $cues = self::LABEL."\n".$language."\n".(string) ($post->caption ?? '');
        $existing = DB::table('synthetic_tracks')
            ->where('post_id', $post->id)
            ->where('language', $language)
            ->where('kind', $kind)
            ->first();
        if ($existing === null) {
            DB::table('synthetic_tracks')->insert([
                'id' => (string) Str::uuid7(),
                'post_id' => $post->id,
                'language' => $language,
                'kind' => $kind,
                'label' => self::LABEL,
                'disclosed' => true,
                'cues' => $cues,
                'created_at' => now(),
            ]);
        }

        return $this->forPost((string) $post->id);
    }

    /**
     * @param  list<string>  $postIds
     * @return array<string, array<string, mixed>>
     */
    public function forPosts(array $postIds): array
    {
        $grouped = $postIds === []
            ? collect()
            : DB::table('synthetic_tracks')->whereIn('post_id', $postIds)->orderBy('language')->get()->groupBy('post_id');
        $out = [];
        foreach ($postIds as $postId) {
            $out[$postId] = $this->shape($grouped->get($postId, collect()));
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function forPost(string $postId): array
    {
        $tracks = DB::table('synthetic_tracks')->where('post_id', $postId)->orderBy('language')->get();

        return $this->shape($tracks);
    }

    /** @param  \Illuminate\Support\Collection<int, object>  $tracks
     * @return array<string, mixed>
     */
    private function shape($tracks): array
    {
        return [
            'label' => self::LABEL,
            'disclosed' => true,
            'tracks' => $tracks->map(fn (object $track): array => [
                'language' => $track->language,
                'kind' => $track->kind,
                'label' => self::LABEL,
                'disclosed' => true,
                'cues' => $track->cues,
                'audio_url' => null,
            ])->all(),
        ];
    }
}

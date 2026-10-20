<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsSocial;
use Tests\TestCase;

final class HashtagTest extends TestCase
{
    use BuildsSocial;
    use RefreshDatabase;

    public function test_search_trending_and_tag_feed_follow_visibility(): void
    {
        $viewer = $this->makeUser();
        $friend = $this->makeUser();
        $blocked = $this->makeUser();
        $this->befriend($viewer, $friend);
        $this->block($viewer, $blocked);

        $this->reel($viewer, 'public', ['streetwear', 'summer'], 10, 4);
        $this->reel($friend, 'friends', ['streetwear'], 1, 0);
        $this->reel($blocked, 'public', ['streetwear'], 50, 20);
        $this->reel($viewer, 'public', ['summer'], 2, 1);

        Sanctum::actingAs($viewer);

        $this->getJson('/api/v1/hashtags/trending')
            ->assertOk()
            ->assertJsonPath('data.0.tag', 'streetwear');

        $this->getJson('/api/v1/hashtags?q=%23Str')
            ->assertOk()
            ->assertJsonPath('data.0.tag', 'streetwear')
            ->assertJsonPath('data.0.posts', 2);

        $this->getJson('/api/v1/hashtags/posts?tag=%23streetwear')
            ->assertOk()
            ->assertJsonPath('meta.tag', 'streetwear')
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/v1/hashtags/posts?tag=nope!')
            ->assertStatus(422)
            ->assertJsonPath('code', 'HASHTAG_INVALID');
    }

    /** @param  list<string>  $tags */
    private function reel(User $author, string $audience, array $tags, int $likes, int $comments): void
    {
        DB::table('feed_posts')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $author->id,
            'kind' => 'media',
            'audience' => $audience,
            'media_type' => 'image',
            'media_path' => 'posts/'.$author->id.'/'.Str::uuid7().'.jpg',
            'tags' => json_encode($tags, JSON_THROW_ON_ERROR),
            'likes_count' => $likes,
            'comments_count' => $comments,
        ]);
    }
}

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

final class ProfileTest extends TestCase
{
    use BuildsSocial;
    use RefreshDatabase;

    public function test_profile_counts_reels_and_friends_and_hides_a_blocked_account(): void
    {
        $viewer = $this->makeUser(['bio' => 'City reels']);
        $friend = $this->makeUser();
        $stranger = $this->makeUser();
        $this->befriend($viewer, $friend);
        $this->mediaPost($viewer, 'public');
        $this->mediaPost($viewer, 'friends');
        $this->mediaPost($friend, 'public');

        Sanctum::actingAs($viewer);
        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.bio', 'City reels')
            ->assertJsonPath('data.counts.posts', 2)
            ->assertJsonPath('data.counts.followers', 0)
            ->assertJsonPath('data.counts.following', 0)
            ->assertJsonPath('data.viewer.is_self', true);
        $this->postJson('/api/v1/users/'.$friend->id.'/follow')->assertCreated();
        $this->getJson('/api/v1/me')
            ->assertJsonPath('data.counts.followers', 0)
            ->assertJsonPath('data.counts.following', 1);
        $this->getJson('/api/v1/users/'.$friend->id)
            ->assertJsonPath('data.counts.followers', 1)
            ->assertJsonPath('data.counts.following', 0)
            ->assertJsonPath('data.viewer.following', true);

        $this->getJson('/api/v1/users/'.$friend->id)
            ->assertOk()
            ->assertJsonPath('data.counts.posts', 1)
            ->assertJsonPath('data.viewer.is_friend', true);

        $this->getJson('/api/v1/users/'.$viewer->id.'/posts')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        Sanctum::actingAs($stranger);
        $this->getJson('/api/v1/users/'.$viewer->id.'/posts')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->block($viewer, $stranger);
        $this->getJson('/api/v1/users/'.$viewer->id)
            ->assertNotFound()
            ->assertJsonPath('code', 'PROFILE_NOT_FOUND');
    }

    private function mediaPost(User $author, string $audience): void
    {
        DB::table('feed_posts')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $author->id,
            'kind' => 'media',
            'audience' => $audience,
            'media_type' => 'image',
            'media_path' => 'posts/'.$author->id.'/'.Str::uuid7().'.jpg',
        ]);
    }
}

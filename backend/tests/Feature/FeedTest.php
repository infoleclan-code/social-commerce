<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FeedPost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsSocial;
use Tests\TestCase;

final class FeedTest extends TestCase
{
    use BuildsSocial;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['feed.media_disk' => 'public', 'feed.cdn_url' => 'https://cdn.example.com']);
    }

    private function imagePost(User $author, array $fields = []): string
    {
        Sanctum::actingAs($author);

        return $this->post('/api/v1/feed', $fields + [
            'media' => UploadedFile::fake()->createWithContent('look.jpg', $this->reelImage(random_int(1, 999))),
            'caption' => 'New drop',
            'audience' => 'public',
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    }

    public function test_posting_an_image_stores_a_metadata_free_copy_and_returns_cdn_urls(): void
    {
        $author = $this->makeUser();
        Sanctum::actingAs($author);

        $response = $this->post('/api/v1/feed', [
            'media' => UploadedFile::fake()->createWithContent('look.jpg', $this->reelImage(7, 3000, 2000)),
            'caption' => 'Fit check',
            'tags' => ['#Streetwear', 'streetwear', 'SNEAKERS'],
            'audience' => 'public',
        ], ['Accept' => 'application/json'])->assertCreated();

        $response->assertJsonPath('data.media.type', 'image')
            ->assertJsonPath('data.media.width', 2160)
            ->assertJsonPath('data.tags', ['streetwear', 'sneakers'])
            ->assertJsonPath('data.viewer.is_author', true);
        $this->assertStringStartsWith('https://cdn.example.com/posts/'.$author->id.'/', $response->json('data.media.url'));

        $stored = FeedPost::query()->firstOrFail();
        Storage::disk('public')->assertExists($stored->media_path);
        $this->assertFalse(@exif_read_data('data://image/jpeg;base64,'.base64_encode(Storage::disk('public')->get($stored->media_path)), 'GPS'));
    }

    public function test_posting_requires_the_social_opt_in(): void
    {
        $private = $this->makeUser(socialOptIn: false);
        Sanctum::actingAs($private);

        $this->post('/api/v1/feed', [
            'media' => UploadedFile::fake()->createWithContent('a.jpg', $this->reelImage(1)),
        ], ['Accept' => 'application/json'])->assertForbidden()->assertJsonPath('code', 'SOCIAL_OPT_IN_REQUIRED');

        Storage::disk('public')->assertDirectoryEmpty('/');
    }

    public function test_visibility_respects_audience_friendship_and_blocks(): void
    {
        $author = $this->makeUser();
        $friend = $this->makeUser();
        $stranger = $this->makeUser();
        $blocked = $this->makeUser();
        $this->befriend($author, $friend);
        $this->befriend($author, $blocked);
        $friendsOnly = $this->imagePost($author, ['audience' => 'friends']);
        $public = $this->imagePost($author, ['audience' => 'public']);

        Sanctum::actingAs($friend);
        $this->getJson('/api/v1/feed?mode=following')->assertOk()->assertJsonCount(2, 'data');

        Sanctum::actingAs($stranger);
        $this->getJson('/api/v1/feed?mode=for_you')->assertOk()->assertJsonPath('data.0.id', $public)->assertJsonCount(1, 'data');
        $this->postJson("/api/v1/feed/{$friendsOnly}/like")->assertNotFound()->assertJsonPath('code', 'POST_NOT_FOUND');

        DB::table('social_connections')->where(fn ($q) => $q->where('addressee_id', $blocked->id)->orWhere('requester_id', $blocked->id))->delete();
        $this->block($author, $blocked);
        Sanctum::actingAs($blocked);
        $this->getJson('/api/v1/feed?mode=for_you')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/v1/feed/{$public}/like")->assertNotFound();
    }

    public function test_likes_are_idempotent_and_counted_by_the_database(): void
    {
        $post = $this->imagePost($this->makeUser());
        $fans = [$this->makeUser(), $this->makeUser(socialOptIn: false)];   // liking needs no opt-in

        foreach ($fans as $fan) {
            Sanctum::actingAs($fan);
            $this->postJson("/api/v1/feed/{$post}/like")->assertOk()->assertJsonPath('data.liked', true);
            $this->postJson("/api/v1/feed/{$post}/like")->assertOk();
        }
        $this->getJson("/api/v1/feed/{$post}/engagement")
            ->assertOk()
            ->assertJsonPath('data.liked', true)
            ->assertJsonPath('data.likes_count', 2)
            ->assertJsonPath('data.comments_count', 0);

        Sanctum::actingAs($fans[0]);
        $this->getJson('/api/v1/feed')->assertJsonPath('data.0.viewer.liked', true)->assertJsonPath('data.0.stats.likes', 2);
        $this->deleteJson("/api/v1/feed/{$post}/like")->assertOk()->assertJsonPath('data.likes_count', 1);
        $this->deleteJson("/api/v1/feed/{$post}/like")->assertOk()->assertJsonPath('data.likes_count', 1);
        $this->getJson('/api/v1/feed')->assertJsonPath('data.0.viewer.liked', false);
    }

    public function test_comments_need_the_opt_in_nest_one_level_and_keep_an_exact_count(): void
    {
        $post = $this->imagePost($this->makeUser());
        $commenter = $this->makeUser();
        Sanctum::actingAs($commenter);

        $top = $this->postJson("/api/v1/feed/{$post}/comments", ['body' => 'Where is this from?'])
            ->assertCreated()
            ->assertJsonPath('meta.comments_count', 1)
            ->json('data.id');
        $reply = $this->postJson("/api/v1/feed/{$post}/comments", ['body' => 'Same question', 'parent_id' => $top])
            ->assertCreated()
            ->assertJsonPath('meta.comments_count', 2)
            ->json('data.id');
        $this->postJson("/api/v1/feed/{$post}/comments", ['body' => 'Too deep', 'parent_id' => $reply])
            ->assertStatus(422)->assertJsonPath('code', 'COMMENT_REJECTED');
        $this->postJson("/api/v1/feed/{$post}/comments", ['body' => '   '])->assertStatus(422);
        $this->assertSame(2, FeedPost::query()->value('comments_count'));

        $this->getJson("/api/v1/feed/{$post}/comments")->assertOk()
            ->assertJsonPath('meta.comments_count', 2)
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.replies_count', 1);
        $this->getJson("/api/v1/feed/{$post}/comments?parent_id={$top}")->assertOk()->assertJsonPath('data.0.id', $reply);

        $this->deleteJson("/api/v1/comments/{$reply}")->assertOk()->assertJsonPath('data.comments_count', 1);
        $this->assertSame(1, FeedPost::query()->value('comments_count'));

        Sanctum::actingAs($this->makeUser(socialOptIn: false));
        $this->postJson("/api/v1/feed/{$post}/comments", ['body' => 'hi'])->assertForbidden()->assertJsonPath('code', 'SOCIAL_OPT_IN_REQUIRED');
    }

    public function test_sharing_a_reel_counts_the_share_and_returns_the_post_link(): void
    {
        $post = $this->imagePost($this->makeUser());
        $sharer = $this->makeUser();
        config(['feed.share_base_url' => 'https://shop.example']);
        Sanctum::actingAs($sharer);

        $this->postJson("/api/v1/feed/{$post}/share", ['channel' => 'whatsapp'])->assertOk()
            ->assertJsonPath('data.shares_count', 1)
            ->assertJsonPath('data.url', "https://shop.example/p/{$post}");
    }

    public function test_for_you_pages_by_rank_without_repeats_and_following_is_chronological(): void
    {
        $authors = [$this->makeUser(), $this->makeUser()];
        $ids = [];
        for ($i = 0; $i < 7; $i++) {
            $ids[] = $this->imagePost($authors[$i % 2]);
        }
        // Engagement lifts an older post above newer ones.
        DB::table('feed_posts')->where('id', $ids[0])->update(['likes_count' => 5000]);

        $viewer = $this->makeUser();
        $this->befriend($viewer, $authors[0]);
        Sanctum::actingAs($viewer);

        $seen = [];
        $cursor = null;
        do {
            $page = $this->getJson('/api/v1/feed?mode=for_you&limit=3'.($cursor ? "&cursor={$cursor}" : ''))->assertOk();
            array_push($seen, ...array_column($page->json('data'), 'id'));
            $cursor = $page->json('meta.next_cursor');
        } while ($cursor !== null);

        $this->assertSame($ids[0], $seen[0], 'the most engaged post ranks first');
        $this->assertCount(7, $seen);
        $this->assertCount(7, array_unique($seen));

        $following = array_column($this->getJson('/api/v1/feed?mode=following&limit=10')->assertOk()->json('data'), 'id');
        $this->assertSame([$ids[6], $ids[4], $ids[2], $ids[0]], $following, 'friends\' posts, newest first');

        $this->getJson('/api/v1/feed?cursor=not-a-cursor')->assertStatus(422)->assertJsonPath('code', 'INVALID_CURSOR');
    }
}

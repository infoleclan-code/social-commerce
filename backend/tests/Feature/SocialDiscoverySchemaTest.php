<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FeedPost;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BuildsSocial;
use Tests\TestCase;

/**
 * The guarantees of the reel schema, proven at the database: counters, media shape,
 * comment rules, and erasure.
 */
final class SocialDiscoverySchemaTest extends TestCase
{
    use BuildsSocial;
    use RefreshDatabase;

    private function mediaPost(User $author): string
    {
        $id = (string) Str::uuid7();
        DB::table('feed_posts')->insert([
            'id' => $id, 'user_id' => $author->id, 'kind' => 'media', 'audience' => 'public',
            'media_type' => 'image', 'media_path' => "posts/{$author->id}/{$id}.jpg",
        ]);

        return $id;
    }

    private function assertRejected(callable $statement, string $needle): void
    {
        try {
            DB::transaction($statement);
            $this->fail("Expected the database to reject: {$needle}");
        } catch (QueryException $rejected) {
            $this->assertStringContainsString($needle, $rejected->getMessage());
        }
    }

    public function test_like_and_comment_counters_are_exact(): void
    {
        $post = $this->mediaPost($this->makeUser());
        $fans = [$this->makeUser(), $this->makeUser(), $this->makeUser()];
        foreach ($fans as $fan) {
            DB::table('feed_post_likes')->insert(['post_id' => $post, 'user_id' => $fan->id]);
        }
        DB::table('feed_post_likes')->where('user_id', $fans[0]->id)->delete();
        $this->assertSame(2, DB::table('feed_posts')->where('id', $post)->value('likes_count'));
        $this->assertRejected(fn () => DB::table('feed_post_likes')->insert(['post_id' => $post, 'user_id' => $fans[1]->id]), 'feed_post_likes_pkey');

        $comment = (string) Str::uuid7();
        DB::table('feed_post_comments')->insert(['id' => $comment, 'post_id' => $post, 'user_id' => $fans[0]->id, 'body' => 'nice']);
        DB::table('feed_post_comments')->insert(['post_id' => $post, 'user_id' => $fans[1]->id, 'body' => 'agree', 'parent_id' => $comment]);
        $this->assertSame(2, DB::table('feed_posts')->where('id', $post)->value('comments_count'));
        DB::table('feed_post_comments')->where('id', $comment)->update(['deleted_at' => now()]);
        $this->assertSame(1, DB::table('feed_posts')->where('id', $post)->value('comments_count'));

        $before = (float) DB::table('feed_posts')->where('id', $post)->value('rank_score');
        DB::table('feed_posts')->where('id', $post)->update(['shares_count' => 100]);
        $this->assertGreaterThan($before, (float) DB::table('feed_posts')->where('id', $post)->value('rank_score'));
    }

    public function test_comment_rules_hold_without_the_application(): void
    {
        $post = $this->mediaPost($this->makeUser());
        $private = $this->makeUser(socialOptIn: false);
        $this->assertRejected(fn () => DB::table('feed_post_comments')->insert(['post_id' => $post, 'user_id' => $private->id, 'body' => 'hi']), 'has not opted in');

        $member = $this->makeUser();
        $top = (string) Str::uuid7();
        $reply = (string) Str::uuid7();
        DB::table('feed_post_comments')->insert(['id' => $top, 'post_id' => $post, 'user_id' => $member->id, 'body' => 'a']);
        DB::table('feed_post_comments')->insert(['id' => $reply, 'post_id' => $post, 'user_id' => $member->id, 'body' => 'b', 'parent_id' => $top]);
        $this->assertRejected(fn () => DB::table('feed_post_comments')->insert(['post_id' => $post, 'user_id' => $member->id, 'body' => 'c', 'parent_id' => $reply]), 'one level deep');

        $other = $this->mediaPost($this->makeUser());
        $this->assertRejected(fn () => DB::table('feed_post_comments')->insert(['post_id' => $other, 'user_id' => $member->id, 'body' => 'x', 'parent_id' => $top]), 'feed_post_comments_parent_fk');
    }

    public function test_media_posts_carry_media_and_videos_need_a_poster(): void
    {
        $author = $this->makeUser();
        $this->assertRejected(fn () => DB::table('feed_posts')->insert(['user_id' => $author->id, 'kind' => 'media', 'audience' => 'public']), 'feed_posts_media_shape');
        $this->assertRejected(fn () => DB::table('feed_posts')->insert([
            'user_id' => $author->id, 'kind' => 'media', 'audience' => 'public', 'media_type' => 'video', 'media_path' => 'posts/x.mp4',
        ]), 'feed_posts_media_shape');
        $this->assertRejected(fn () => DB::table('feed_posts')->insert([
            'user_id' => $author->id, 'kind' => 'media', 'audience' => 'public', 'media_type' => 'image', 'media_path' => 'p.jpg', 'tags' => '[1, 2]',
        ]), 'feed_posts_tags_shape');
        $private = $this->makeUser(socialOptIn: false);
        $this->assertRejected(fn () => DB::table('feed_posts')->insert([
            'user_id' => $private->id, 'kind' => 'media', 'audience' => 'public', 'media_type' => 'image', 'media_path' => 'p.jpg',
        ]), 'has not opted in');
    }

    public function test_erasure_removes_likes_and_redacts_comments(): void
    {
        $user = $this->makeUser(['bio' => 'Posts from the city']);
        $post = $this->mediaPost($this->makeUser());
        DB::table('feed_post_likes')->insert(['post_id' => $post, 'user_id' => $user->id]);
        DB::table('feed_post_comments')->insert(['post_id' => $post, 'user_id' => $user->id, 'body' => 'my opinion']);

        DB::select('SELECT anonymize_user(?)', [$user->id]);

        $erased = DB::table('users')->where('id', $user->id)->first();
        $this->assertNull($erased->bio);
        $this->assertSame('{}', $erased->settings);
        $this->assertSame(0, DB::table('feed_post_likes')->where('user_id', $user->id)->count());
        $this->assertSame('[deleted]', DB::table('feed_post_comments')->where('user_id', $user->id)->value('body'));
        $this->assertSame(0, FeedPost::query()->whereKey($post)->value('likes_count'));
    }
}

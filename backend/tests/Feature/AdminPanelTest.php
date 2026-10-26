<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\BuildsCommerce;
use Tests\Support\BuildsSocial;
use Tests\TestCase;

final class AdminPanelTest extends TestCase
{
    use BuildsCommerce;
    use BuildsSocial;
    use RefreshDatabase;

    private string $secret = 'KZXW6YTBONSWG33OMVZXG33O';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['feed.media_disk' => 'public', 'feed.cdn_url' => 'https://cdn.example.com', 'admin.demo_otp' => '']);
    }

    public function test_admin_routes_require_the_admin_token_and_a_one_time_code(): void
    {
        $admin = $this->admin();
        $this->makeUser(['email' => 'member@example.com', 'password' => 'correct-horse']);
        $memberToken = (string) $this->postJson('/api/v1/auth/login', [
            'email' => 'member@example.com',
            'password' => 'correct-horse',
        ])->assertOk()->json('data.token');
        $this->withToken($memberToken)->getJson('/api/v1/admin/posts')->assertForbidden()->assertJsonPath('code', 'ADMIN_REQUIRED');

        $this->app['auth']->forgetGuards();
        $mobile = (string) $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'correct-horse',
        ])->assertOk()->json('data.token');
        $this->withToken($mobile)->getJson('/api/v1/admin/posts')->assertForbidden();

        $this->postJson('/api/v1/admin/login', [
            'email' => 'admin@example.com',
            'password' => 'correct-horse',
            'otp' => '000000',
        ])->assertStatus(422)->assertJsonPath('code', 'AUTH_FAILED');

        $this->app['auth']->forgetGuards();
        $token = $this->adminToken();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/admin/posts')->assertOk();
        $this->assertSame(UserRole::Admin, $admin->role);
    }

    public function test_admin_moderation_is_throttled(): void
    {
        $token = $this->adminToken();
        for ($attempt = 0; $attempt < 60; $attempt++) {
            $this->withToken($token)->getJson('/api/v1/admin/users')->assertOk();
        }
        $this->withToken($token)->getJson('/api/v1/admin/users')->assertStatus(429)->assertJsonPath('code', 'RATE_LIMITED');
    }

    #[Group('purchases')]
    public function test_an_admin_can_schedule_and_end_an_auction(): void
    {
        $product = $this->catalogProduct(5_000);
        $token = $this->adminToken();
        $created = $this->withToken($token)->postJson('/api/v1/admin/auctions', [
            'product_id' => $product,
            'start_minor' => 1_000,
            'starts_at' => now()->addHour()->toIso8601String(),
            'ends_at' => now()->addHours(3)->toIso8601String(),
        ])->assertCreated()->json('data');
        $this->assertFalse($created['ended']);
        $this->getJson('/api/v1/auctions/live')->assertOk()->assertJsonPath('data.auction', null);

        $this->withToken($token)->postJson('/api/v1/admin/auctions/'.$created['id'].'/schedule', [
            'starts_at' => now()->subMinute()->toIso8601String(),
            'ends_at' => now()->addHour()->toIso8601String(),
        ])->assertOk();
        Sanctum::actingAs($this->makeUser());
        $this->getJson('/api/v1/auctions/live')->assertOk()->assertJsonPath('data.auction.id', $created['id']);

        $this->withToken($token)->postJson('/api/v1/admin/auctions/'.$created['id'].'/end')->assertOk()->assertJsonPath('data.ended', true);
        $this->getJson('/api/v1/auctions/live')->assertOk()->assertJsonPath('data.auction', null);
    }

    #[Group('purchases')]
    public function test_listing_status_and_seller_verification_are_staff_actions(): void
    {
        $product = $this->catalogProduct(2_000, ['title' => 'Staff coat']);
        $seller = $this->verifiedSeller();
        $token = $this->adminToken();

        $this->withToken($token)->patchJson('/api/v1/admin/products/'.$product, [
            'listing_status' => 'blocked',
        ])->assertOk()->assertJsonPath('data.listing_status', 'blocked');
        $this->assertSame('blocked', DB::table('products')->where('id', $product)->value('listing_status'));

        $this->withToken($token)->postJson('/api/v1/admin/products', [
            'title' => 'Staff scarf',
            'brand' => 'Atelier',
            'image_url' => 'https://images.example.com/scarf.jpg',
            'price_minor' => 4_000,
            'stock' => 3,
        ])->assertCreated()->assertJsonPath('data.listing_status', 'active');

        $this->withToken($token)->postJson('/api/v1/admin/sellers/'.$seller->id.'/reject')
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.charges_enabled', false);
        $this->withToken($token)->postJson('/api/v1/admin/sellers/'.$seller->id.'/verify')
            ->assertOk()
            ->assertJsonPath('data.status', 'verified');
    }

    public function test_moderation_hides_and_removes_posts_and_comments_and_bans_a_user(): void
    {
        $author = $this->makeUser();
        $post = $this->imagePost($author);
        Sanctum::actingAs($author);
        $comment = (string) $this->postJson('/api/v1/feed/'.$post.'/comments', ['body' => 'hello there'])->assertCreated()->json('data.id');

        $viewer = $this->makeUser();
        Sanctum::actingAs($viewer);
        $this->getJson('/api/v1/feed?mode=for_you')->assertOk()->assertJsonFragment(['id' => $post]);

        $token = $this->adminToken();
        $this->withToken($token)->postJson('/api/v1/admin/posts/'.$post.'/hide')->assertOk();
        Sanctum::actingAs($viewer);
        $this->getJson('/api/v1/feed?mode=for_you')->assertOk()->assertJsonMissing(['id' => $post]);
        $this->getJson('/api/v1/feed/'.$post.'/comments')->assertNotFound();

        $this->withToken($token)->postJson('/api/v1/admin/posts/'.$post.'/unhide')->assertOk();
        $this->withToken($token)->postJson('/api/v1/admin/comments/'.$comment.'/hide')->assertOk();
        Sanctum::actingAs($viewer);
        $this->getJson('/api/v1/feed/'.$post.'/comments')->assertOk()->assertJsonMissing(['id' => $comment]);
        $this->withToken($token)->deleteJson('/api/v1/admin/comments/'.$comment)->assertNoContent();

        $this->withToken($token)->postJson('/api/v1/admin/users/'.$author->id.'/ban')->assertOk();
        $this->postJson('/api/v1/auth/login', [
            'email' => $author->email,
            'password' => 'secret-pass',
        ])->assertStatus(422);
        $author->forceFill(['password' => 'correct-horse'])->save();
        $this->postJson('/api/v1/auth/login', [
            'email' => $author->email,
            'password' => 'correct-horse',
        ])->assertForbidden()->assertJsonPath('code', 'ACCOUNT_BANNED');
        $this->withToken($token)->postJson('/api/v1/admin/users/'.$author->id.'/unban')->assertOk();
    }

    private function admin(): User
    {
        $user = User::query()->whereRaw('email = ?', ['admin@example.com'])->first()
            ?? $this->makeUser([
                'email' => 'admin@example.com',
                'username' => 'demo_admin',
                'password' => 'correct-horse',
            ]);
        $user->forceFill([
            'role' => UserRole::Admin,
            'password' => 'correct-horse',
            'totp_secret' => Crypt::encryptString($this->secret),
            'totp_last_step' => null,
        ])->save();

        return $user->refresh();
    }

    private function adminToken(): string
    {
        $this->admin();

        return (string) $this->postJson('/api/v1/admin/login', [
            'email' => 'admin@example.com',
            'password' => 'correct-horse',
            'otp' => Totp::code($this->secret, intdiv(time(), 30)),
        ])->assertOk()->json('data.token');
    }

    public function test_a_reused_admin_code_fails_the_same_way_as_a_wrong_password(): void
    {
        $this->admin();
        $code = Totp::code($this->secret, intdiv(time(), 30));
        $this->postJson('/api/v1/admin/login', [
            'email' => 'admin@example.com',
            'password' => 'correct-horse',
            'otp' => $code,
        ])->assertOk();
        $reused = $this->postJson('/api/v1/admin/login', [
            'email' => 'admin@example.com',
            'password' => 'correct-horse',
            'otp' => $code,
        ])->assertStatus(422);
        $wrong = $this->postJson('/api/v1/admin/login', [
            'email' => 'admin@example.com',
            'password' => 'not-the-password',
            'otp' => '000000',
        ])->assertStatus(422);
        $reused->assertJsonPath('code', 'AUTH_FAILED');
        $wrong->assertJsonPath('code', 'AUTH_FAILED');
        $this->assertSame($reused->json('message'), $wrong->json('message'));
    }

    private function imagePost(User $author): string
    {
        Sanctum::actingAs($author);

        return (string) $this->post('/api/v1/feed', [
            'media' => UploadedFile::fake()->createWithContent('look.jpg', $this->reelImage(7)),
            'caption' => 'New drop',
            'audience' => 'public',
            'tags' => ['sneakers'],
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Search\VectorStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsCommerce;
use Tests\Support\BuildsSocial;
use Tests\TestCase;

/**
 * Fixes from the commerce audit: money, visibility, auth, friendship, cart, and erasure.
 */
final class AuditFixesTest extends TestCase
{
    use BuildsCommerce;
    use BuildsSocial;
    use RefreshDatabase;

    public function test_an_unpaid_squad_join_does_not_unlock_the_price(): void
    {
        $host = $this->makeUser();
        $this->savedCard($host);
        Sanctum::actingAs($host);
        $squad = (string) $this->postJson('/api/v1/squads/authorize', ['product_id' => $this->catalogProduct(10_000)], $this->key('host'))
            ->assertCreated()
            ->json('data.squad_id');
        $before = (string) DB::table('payment_authorizations')
            ->where('order_id', DB::table('orders')->where('squad_id', $squad)->value('id'))
            ->value('capture_after');

        Sanctum::actingAs($this->makeUser());
        $this->postJson('/api/v1/squads/'.$squad.'/join')->assertOk()->assertJsonPath('data.members', 2);
        Sanctum::actingAs($host);
        $this->postJson('/api/v1/squads/'.$squad.'/settle')
            ->assertOk()
            ->assertJsonPath('data.price_kind', 'solo')
            ->assertJsonPath('data.members', 1);
        $after = (string) DB::table('payment_authorizations')
            ->where('order_id', DB::table('orders')->where('squad_id', $squad)->value('id'))
            ->value('capture_after');
        $this->assertSame($before, $after);

        Sanctum::actingAs($this->makeUser());
        $this->postJson('/api/v1/squads/'.$squad.'/settle')->assertNotFound();
    }

    public function test_checkout_and_one_tap_charge_the_landed_total_the_buyer_was_shown(): void
    {
        $buyer = $this->makeUser(['country_code' => 'DE']);
        $this->savedCard($buyer);
        $product = $this->catalogProduct(10_000, [
            'ships_from' => 'US',
            'shipping_minor' => 500,
            'vat_bps' => 2000,
            'duty_bps' => 400,
        ]);
        Sanctum::actingAs($buyer);

        $this->postJson('/api/v1/checkout/one-tap', [
            'product_id' => $product,
            'expected_total_minor' => 10_000,
        ], $this->key('stale'))->assertStatus(409)->assertJsonPath('code', 'PRICE_CHANGED')->assertJsonPath('meta.total.amount_minor', 13_960);
        $this->assertSame(5, (int) DB::table('products')->where('id', $product)->value('stock'));

        $this->postJson('/api/v1/checkout/one-tap', [
            'product_id' => $product,
            'expected_total_minor' => 13_960,
        ], $this->key('pay'))->assertCreated()->assertJsonPath('data.order.total.amount_minor', 13_960);

        $second = $this->catalogProduct(10_000, [
            'ships_from' => 'US',
            'shipping_minor' => 500,
            'vat_bps' => 2000,
            'duty_bps' => 400,
        ]);
        $this->postJson('/api/v1/checkout', [
            'product_id' => $second,
            'expected_total_minor' => 13_960,
        ], $this->key('cart'))->assertCreated()->assertJsonPath('data.total.amount_minor', 13_960);
    }

    public function test_a_live_auction_is_not_created_by_reading_and_a_bid_holds_a_card(): void
    {
        $buyer = $this->makeUser();
        $this->savedCard($buyer);
        Sanctum::actingAs($buyer);
        $this->getJson('/api/v1/auctions/live')->assertOk()->assertJsonPath('data.auction', null);
        $this->assertSame(0, DB::table('auctions')->count());

        $product = $this->catalogProduct(2_000);
        $auction = (string) Str::uuid7();
        DB::table('auctions')->insert([
            'id' => $auction,
            'product_id' => $product,
            'start_minor' => 1_000,
            'current_minor' => 1_000,
            'ends_at' => now()->addHour(),
            'created_at' => now(),
        ]);
        Sanctum::actingAs($this->makeUser());
        $this->postJson('/api/v1/auctions/'.$auction.'/bids', ['amount_minor' => 1_100])->assertStatus(422)->assertJsonPath('code', 'PAYMENT_METHOD_REQUIRED');
        $this->assertSame(0, DB::table('auction_bids')->count());

        Sanctum::actingAs($buyer);
        $this->postJson('/api/v1/auctions/'.$auction.'/bids', ['amount_minor' => 1_100])->assertOk();
        $first = (string) DB::table('auction_bids')->value('payment_intent_id');
        $this->assertNotSame('', $first);
        $this->assertSame('requires_capture', $this->stripe()->intents[$first]['status']);

        $rival = $this->makeUser();
        $this->savedCard($rival);
        Sanctum::actingAs($rival);
        $this->postJson('/api/v1/auctions/'.$auction.'/bids', ['amount_minor' => 1_200])->assertOk()->assertJsonPath('data.current.amount_minor', 1_200);
        $this->assertSame('canceled', $this->stripe()->intents[$first]['status']);
        $this->getJson('/api/v1/auctions/live')->assertOk()->assertJsonPath('data.auction.id', $auction);
    }

    public function test_opening_a_loot_box_twice_with_one_key_debits_once(): void
    {
        $user = $this->makeUser();
        DB::table('user_wallets')->insert(['user_id' => $user->id, 'coins' => 250, 'updated_at' => now()]);
        Sanctum::actingAs($user);
        $headers = $this->key('loot');
        $this->postJson('/api/v1/rewards/loot-box', [], $headers)->assertOk()->assertJsonPath('data.coins', 150);
        $this->postJson('/api/v1/rewards/loot-box', [], $headers)->assertOk()->assertJsonPath('data.coins', 150);
        $this->assertSame(150, (int) DB::table('user_wallets')->where('user_id', $user->id)->value('coins'));
        $this->assertSame(1, DB::table('coupons')->where('user_id', $user->id)->count());
    }

    public function test_the_catalog_is_private_and_skips_unverified_or_unlisted_products(): void
    {
        $this->getJson('/api/v1/catalog/feed')->assertUnauthorized();
        $viewer = $this->makeUser();
        $live = $this->catalogProduct(1_000, ['title' => 'Live piece']);
        $this->catalogProduct(1_000, ['listing_status' => 'review', 'title' => 'Hidden']);
        $pending = $this->makeUser();
        DB::table('sellers')->insert([
            'user_id' => $pending->id,
            'legal_name' => 'Pending Co',
            'country' => 'US',
            'registration_number' => 'P-1',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->catalogProduct(1_000, ['seller_id' => $pending->id, 'title' => 'Unverified']);
        $verified = $this->catalogProduct(1_000, ['seller_id' => $this->verifiedSeller()->id, 'title' => 'Verified']);
        Sanctum::actingAs($viewer);
        $ids = collect($this->getJson('/api/v1/catalog/feed')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($live));
        $this->assertTrue($ids->contains($verified));
        $this->assertCount(2, $ids);
    }

    public function test_a_store_hides_products_that_were_only_tagged_on_a_friends_reel(): void
    {
        $author = $this->makeUser();
        $friend = $this->makeUser();
        $stranger = $this->makeUser();
        $this->befriend($author, $friend);
        $product = $this->catalogProduct();
        $post = (string) Str::uuid7();
        DB::table('feed_posts')->insert([
            'id' => $post,
            'user_id' => $author->id,
            'kind' => 'media',
            'audience' => 'friends',
            'media_type' => 'image',
            'media_path' => 'posts/'.$post.'.jpg',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('reel_product_tags')->insert(['post_id' => $post, 'product_id' => $product, 'created_at' => now()]);

        Sanctum::actingAs($stranger);
        $this->getJson('/api/v1/users/'.$author->id.'/store')->assertOk()->assertJsonCount(0, 'data');
        Sanctum::actingAs($friend);
        $this->getJson('/api/v1/users/'.$author->id.'/store')->assertOk()->assertJsonPath('data.0.id', $product);
    }

    public function test_a_gift_address_is_encrypted_and_the_claimer_can_retry(): void
    {
        $buyer = $this->makeUser();
        $this->savedCard($buyer);
        Sanctum::actingAs($buyer);
        $gift = $this->postJson('/api/v1/gifts', ['product_id' => $this->catalogProduct(4_000)], $this->key('gift'))->assertCreated()->json('data');
        $order = (string) $gift['order_id'];
        $this->expireUndoWindow($order);
        $this->captureDue();

        $recipient = $this->makeUser();
        Sanctum::actingAs($recipient);
        $address = ['line' => '1 Main St', 'locality' => 'Tbilisi', 'postal_code' => '0100', 'country' => 'GE'];
        $this->postJson('/api/v1/gifts/'.$gift['token'].'/claim', $address)->assertOk()->assertJsonPath('data.address.line', '1 Main St');
        $this->postJson('/api/v1/gifts/'.$gift['token'].'/claim', $address)->assertOk()->assertJsonPath('data.status', 'claimed');
        $stored = DB::table('gifts')->where('token', $gift['token'])->first();
        $this->assertNull($stored->address_line);
        $this->assertIsString($stored->address_ciphertext);
        $this->assertStringNotContainsString('1 Main St', (string) $stored->address_ciphertext);

        Sanctum::actingAs($buyer);
        $this->getJson('/api/v1/gifts/'.$gift['token'])->assertOk()->assertJsonMissingPath('data.address');
    }

    public function test_register_login_logout_and_expiry(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'email' => 'ada@example.com',
            'password' => 'correct-horse',
            'username' => 'ada_lovelace',
            'display_name' => 'Ada',
        ])->assertCreated()->assertJsonPath('data.user.username', 'ada_lovelace');

        $token = (string) $this->postJson('/api/v1/auth/login', [
            'email' => 'ada@example.com',
            'password' => 'correct-horse',
        ])->assertOk()->json('data.token');
        $this->postJson('/api/v1/auth/login', ['email' => 'ada@example.com', 'password' => 'nope'])->assertStatus(422)->assertJsonPath('code', 'AUTH_FAILED');

        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.username', 'ada_lovelace');
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertSame(1, DB::table('personal_access_tokens')->count(), 'Logout revokes the token that was used, not every session.');
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/me')->assertUnauthorized();

        $this->app['auth']->forgetGuards();
        $again = (string) $this->postJson('/api/v1/auth/login', [
            'email' => 'ada@example.com',
            'password' => 'correct-horse',
        ])->json('data.token');
        DB::table('personal_access_tokens')->update(['expires_at' => now()->subMinute()]);
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$again)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_friend_requests_are_mutual_and_counts_stay_the_accepted_friend_count(): void
    {
        $ada = $this->makeUser();
        $bea = $this->makeUser();
        Sanctum::actingAs($ada);
        $this->postJson('/api/v1/users/'.$bea->id.'/friend-request')->assertCreated()->assertJsonPath('data.connection', 'pending_out');
        Sanctum::actingAs($bea);
        $this->postJson('/api/v1/users/'.$ada->id.'/friend-accept')->assertOk()->assertJsonPath('data.connection', 'friends');
        $this->getJson('/api/v1/me')->assertOk()
            ->assertJsonPath('data.counts.followers', 1)
            ->assertJsonPath('data.counts.following', 1)
            ->assertJsonPath('data.viewer.connection', 'self');

        $cam = $this->makeUser();
        Sanctum::actingAs($ada);
        $this->postJson('/api/v1/users/'.$cam->id.'/block')->assertOk();
        Sanctum::actingAs($cam);
        $this->getJson('/api/v1/users/'.$ada->id)->assertNotFound();
    }

    public function test_cart_checkout_uses_an_address_and_the_seller_can_add_tracking(): void
    {
        $seller = $this->verifiedSeller();
        $buyer = $this->makeUser();
        $this->savedCard($buyer);
        $product = $this->catalogProduct(10_000, [
            'seller_id' => $seller->id,
            'ships_from' => 'US',
            'shipping_minor' => 500,
            'vat_bps' => 2000,
            'duty_bps' => 400,
        ]);
        Sanctum::actingAs($buyer);
        $this->putJson('/api/v1/cart/'.$product, ['quantity' => 1])->assertOk()->assertJsonPath('data.0.quantity', 1);
        $address = (string) $this->postJson('/api/v1/addresses', [
            'line' => '12 Rustaveli Ave',
            'locality' => 'Tbilisi',
            'postal_code' => '0108',
            'country' => 'DE',
            'is_default' => true,
        ])->assertCreated()->json('data.id');
        $this->assertStringNotContainsString('Rustaveli', (string) DB::table('shipping_addresses')->where('id', $address)->value('address_ciphertext'));

        $order = (string) $this->postJson('/api/v1/cart/checkout', ['address_id' => $address], $this->key('bag'))
            ->assertCreated()
            ->json('data.orders.0.id');
        $this->assertSame(13_960, (int) DB::table('orders')->where('id', $order)->value('total_minor'));
        $this->assertSame(0, DB::table('cart_items')->where('user_id', $buyer->id)->count());
        $this->getJson('/api/v1/orders')->assertOk()->assertJsonPath('data.0.fulfillment', 'preparing');

        Sanctum::actingAs($seller);
        $this->postJson('/api/v1/seller/orders/'.$order.'/ship', ['carrier' => 'DHL', 'tracking_number' => 'JD0146000061234567'])->assertOk();
        Sanctum::actingAs($buyer);
        $this->getJson('/api/v1/orders/'.$order)->assertOk()
            ->assertJsonPath('data.fulfillment', 'shipped')
            ->assertJsonPath('data.tracking_number', 'JD0146000061234567');
        $this->postJson('/api/v1/orders/'.$order.'/withdraw', [], $this->key('back'))->assertOk();
    }

    public function test_pause_unpause_export_and_anonymize_after_the_grace_period(): void
    {
        $user = $this->makeUser();
        $post = (string) Str::uuid7();
        DB::table('feed_posts')->insert([
            'id' => $post,
            'user_id' => $user->id,
            'kind' => 'media',
            'audience' => 'public',
            'media_type' => 'image',
            'media_path' => 'posts/'.$post.'.jpg',
            'caption' => 'hello',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/trust/pause')->assertOk()->assertJsonPath('data.paused', true);
        $this->postJson('/api/v1/trust/unpause')->assertOk()->assertJsonPath('data.paused', false);
        $export = $this->postJson('/api/v1/trust/export')->assertOk()->json('data');
        $this->assertSame($user->email, $export['user']['email']);
        $this->assertSame($post, $export['posts'][0]['id']);
        $this->assertArrayNotHasKey('expo_push_token', (array) ($export['devices'][0] ?? []));

        $this->postJson('/api/v1/devices', [
            'platform' => 'ios',
            'expo_push_token' => 'ExponentPushToken[auditfixdevice01]',
        ])->assertCreated();
        $this->postJson('/api/v1/trust/delete')->assertOk();
        $this->postJson('/api/v1/trust/unpause')->assertStatus(409);
        $this->assertNull(DB::table('users')->where('id', $user->id)->value('anonymized_at'));

        $this->travel(15)->days();
        Artisan::call('accounts:anonymize-due');
        $scrubbed = DB::table('users')->where('id', $user->id)->first();
        $this->assertNotNull($scrubbed->anonymized_at);
        $this->assertStringStartsWith('erased_', (string) $scrubbed->username);
        $this->assertNull(DB::table('feed_posts')->where('id', $post)->first());
        $this->assertSame(0, DB::table('user_devices')->where('user_id', $user->id)->count());
    }

    public function test_pgvector_below_0_8_does_not_create_vector_columns(): void
    {
        $version = DB::selectOne("SELECT extversion FROM pg_extension WHERE extname = 'vector'");
        if ($version !== null && version_compare((string) $version->extversion, '0.8.0', '>=')) {
            $this->assertTrue(app(VectorStore::class)->usesPgvector());

            return;
        }
        $this->assertFalse(app(VectorStore::class)->usesPgvector());
    }
}

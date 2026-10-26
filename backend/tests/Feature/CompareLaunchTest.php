<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\PriceDropAlert;
use App\Services\Commerce\CartService;
use App\Services\Compare\CompareService;
use App\Services\Compare\OfferProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\Support\BuildsCommerce;
use Tests\Support\BuildsSocial;
use Tests\TestCase;

final class CompareLaunchTest extends TestCase
{
    use BuildsCommerce;
    use BuildsSocial;
    use RefreshDatabase;

    public function test_search_returns_the_three_cheapest_in_stock_stores(): void
    {
        Sanctum::actingAs($this->makeUser());
        $response = $this->getJson('/api/v1/compare?q=nike%20sneakers')->assertOk();
        $offers = $response->json('data.products.0.offers');
        $this->assertCount(3, $offers);
        $this->assertSame(['Finish Line', "Dick's", 'Foot Locker'], array_column($offers, 'store'));
        $this->assertSame([10500, 11100, 11800], array_column($offers, 'total_minor'));
        $this->assertSame('in_stock', $offers[0]['availability']);
        $this->assertNotEmpty($offers[0]['checked_at']);
        $this->assertArrayNotHasKey('url', $offers[0]);
        $this->assertStringNotContainsString('StockX', $response->getContent());
        $this->assertStringNotContainsString('Nike.com', $response->getContent());
    }

    public function test_filters_currency_and_unavailable_offers(): void
    {
        Sanctum::actingAs($this->makeUser());
        $this->getJson('/api/v1/compare?q=nike&size=9')->assertOk()->assertJsonPath('data.products', []);
        $this->getJson('/api/v1/compare?q=samba&brand=Adidas')->assertOk()->assertJsonPath('data.products.0.brand', 'Adidas');
        $this->getJson('/api/v1/compare?q=nike&max_minor=10600')
            ->assertOk()
            ->assertJsonPath('data.products.0.offers.0.store', 'Finish Line')
            ->assertJsonCount(1, 'data.products.0.offers');

        $euros = $this->getJson('/api/v1/compare?q=nike&currency=EUR')->assertOk()->json('data.products.0.offers.0');
        $this->assertSame('EUR', $euros['currency']);
        $this->assertSame(9660, $euros['total_minor']);

        $withUnavailable = $this->getJson('/api/v1/compare?q=nike&in_stock=0')->assertOk()->json('data.products.0');
        $this->assertSame('StockX', $withUnavailable['unavailable'][0]['store']);
        $this->assertSame('out_of_stock', $withUnavailable['unavailable'][0]['availability']);
        $this->assertNotContains('StockX', array_column($withUnavailable['offers'], 'store'));
    }

    public function test_click_records_the_host_and_tags_the_outbound_link(): void
    {
        config(['compare.affiliate_param' => 'tag', 'compare.affiliate_id' => 'demo-tag']);
        $user = $this->makeUser();
        Sanctum::actingAs($user);
        $offerId = (string) $this->getJson('/api/v1/compare?q=nike')->json('data.products.0.offers.0.id');

        $this->postJson('/api/v1/compare/clicks', ['offer_id' => $offerId])
            ->assertOk()
            ->assertJsonPath('data.url', 'https://www.finishline.com/nike-dunk-low?tag=demo-tag');
        $this->postJson('/api/v1/compare/clicks', ['offer_id' => str_repeat('a', 24)])->assertNotFound()->assertJsonPath('code', 'OFFER_EXPIRED');

        $click = DB::table('outbound_clicks')->where('user_id', $user->id)->first();
        $this->assertSame('www.finishline.com', $click->host);
        $this->assertSame($offerId, $click->offer_key);
        $this->assertStringNotContainsString('tag=', (string) json_encode($click));
    }

    public function test_a_provider_failure_does_not_return_a_stale_cache(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);
        $key = app(CompareService::class)->cacheKey('nike');
        Cache::put($key, [
            'checked_at' => time() - 10_000,
            'offers' => [[
                'store' => 'StaleMart',
                'title' => 'Old',
                'url' => 'https://stale.example/old',
                'brand' => 'Nike',
                'model' => 'Old',
                'size' => '10',
                'price_minor' => 100,
                'shipping_minor' => 0,
                'shipping_known' => true,
                'currency' => 'USD',
                'availability' => 'in_stock',
            ]],
        ], 3600);
        $this->app->instance(OfferProvider::class, new class implements OfferProvider
        {
            public function search(string $query, array $context = []): array
            {
                throw new RuntimeException('down');
            }
        });

        $this->getJson('/api/v1/compare?q=nike')
            ->assertStatus(503)
            ->assertJsonPath('code', 'COMPARE_UNAVAILABLE');
        $this->assertStringNotContainsString('StaleMart', $this->getJson('/api/v1/compare?q=nike')->getContent());
    }

    public function test_private_profiles_are_hidden_and_friends_profiles_stay_with_friends(): void
    {
        $owner = $this->makeUser();
        $friend = $this->makeUser();
        $stranger = $this->makeUser();
        $this->befriend($owner, $friend);
        $owner->forceFill(['profile_visibility' => 'friends'])->save();

        Sanctum::actingAs($stranger);
        $this->getJson('/api/v1/users/'.$owner->id)->assertNotFound()->assertJsonPath('code', 'PROFILE_NOT_FOUND');
        Sanctum::actingAs($friend);
        $this->getJson('/api/v1/users/'.$owner->id)->assertOk()->assertJsonPath('data.username', $owner->username);
        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/users/'.$owner->id)->assertOk();
    }

    public function test_registration_conflicts_share_one_error_and_devices_are_not_reassigned(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'email' => 'ada@example.com',
            'password' => 'correct-horse',
            'username' => 'ada_lovelace',
            'display_name' => 'Ada',
        ])->assertCreated();
        $email = $this->postJson('/api/v1/auth/register', [
            'email' => 'ada@example.com',
            'password' => 'correct-horse',
            'username' => 'ada_other',
            'display_name' => 'Ada',
        ])->assertStatus(422);
        $username = $this->postJson('/api/v1/auth/register', [
            'email' => 'other@example.com',
            'password' => 'correct-horse',
            'username' => 'ada_lovelace',
            'display_name' => 'Ada',
        ])->assertStatus(422);
        $email->assertJsonPath('code', 'ACCOUNT_UNAVAILABLE');
        $username->assertJsonPath('code', 'ACCOUNT_UNAVAILABLE');
        $this->assertSame($email->json('message'), $username->json('message'));

        $first = $this->makeUser();
        $second = $this->makeUser();
        $token = 'ExponentPushToken[comparelaunchdevice1]';
        Sanctum::actingAs($first);
        $this->postJson('/api/v1/devices', ['platform' => 'ios', 'expo_push_token' => $token])->assertCreated();
        Sanctum::actingAs($second);
        $this->postJson('/api/v1/devices', ['platform' => 'ios', 'expo_push_token' => $token])
            ->assertStatus(409)
            ->assertJsonPath('code', 'DEVICE_TOKEN_TAKEN');
        $this->assertSame($first->id, DB::table('user_devices')->where('expo_push_token', $token)->value('user_id'));
    }

    public function test_export_and_delete_require_the_current_password(): void
    {
        $user = $this->makeUser();
        $user->forceFill(['password' => 'correct-horse'])->save();
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/trust/export')->assertStatus(422);
        $this->postJson('/api/v1/trust/export', ['password' => 'nope'])->assertStatus(422)->assertJsonPath('code', 'PASSWORD_MISMATCH');
        $this->postJson('/api/v1/trust/delete', ['password' => 'nope'])->assertStatus(422)->assertJsonPath('code', 'PASSWORD_MISMATCH');
    }

    public function test_web_session_uses_the_cookie_only_with_the_custom_header(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'email' => 'web@example.com',
            'password' => 'correct-horse',
            'username' => 'web_user',
            'display_name' => 'Web',
        ])->assertCreated();
        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'web@example.com',
            'password' => 'correct-horse',
        ])->assertOk();
        $token = (string) $login->json('data.token');
        $this->assertSame($token, $login->getCookie('auth_token', false)->getValue());

        $this->app['auth']->forgetGuards();
        $this->call('GET', '/api/v1/me', [], ['auth_token' => $token], [], $this->cookieServer(false))->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->call('GET', '/api/v1/me', [], ['auth_token' => $token], [], $this->cookieServer(true))
            ->assertOk()
            ->assertJsonPath('data.username', 'web_user');
    }

    public function test_deleting_an_address_referenced_by_an_order_clears_the_foreign_key(): void
    {
        $buyer = $this->makeUser();
        $address = (string) Str::uuid7();
        DB::table('shipping_addresses')->insert([
            'id' => $address,
            'user_id' => $buyer->id,
            'country' => 'GE',
            'address_ciphertext' => Crypt::encryptString('{"line":"12 Rustaveli Ave"}'),
            'is_default' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $order = $this->capturedOrder($buyer, $this->catalogProduct(), 2_000, ['shipping_address_id' => $address]);

        app(CartService::class)->deleteAddress($buyer, $address);

        $this->assertNull(DB::table('orders')->where('id', $order)->value('shipping_address_id'));
        $this->assertSame(0, DB::table('shipping_addresses')->where('id', $address)->count());
    }

    public function test_purchase_routes_are_missing_when_the_launch_flag_is_off(): void
    {
        config(['commerce.purchases_enabled' => false]);
        $this->postJson('/api/v1/checkout', [])->assertNotFound();
        $this->postJson('/api/v1/gifts', [])->assertNotFound();
        foreach (['/api/v1/cart', '/api/v1/auctions/live', '/api/v1/blind-boxes', '/api/v1/payment-methods', '/api/v1/orders'] as $path) {
            $this->getJson($path)->assertNotFound();
        }
        $this->postJson('/api/v1/rewards/loot-box', [])->assertNotFound();
        $this->getJson('/api/v1/admin/auctions')->assertNotFound();
        $this->postJson('/api/v1/stripe/webhook', [])->assertNotFound();
        $this->getJson('/api/v1/cards/squad/'.Str::uuid7())->assertNotFound();
        $this->getJson('/api/v1/cards/auction/'.Str::uuid7())->assertNotFound();
        $this->getJson('/api/v1/cards/gift/not-a-real-token')->assertNotFound();
    }

    public function test_payment_commands_do_nothing_when_purchases_are_off(): void
    {
        config(['commerce.purchases_enabled' => false]);
        foreach (['payments:capture-due', 'payments:reconcile', 'ledger:release-commissions', 'price-protection:sweep', 'gifts:refund-expired', 'drops:rotate'] as $command) {
            $this->artisan($command)->assertSuccessful()->expectsOutputToContain('In-app purchases are off.');
        }
    }

    public function test_unknown_shipping_ranks_by_item_price_and_a_missing_rate_is_not_relabeled(): void
    {
        $this->app->instance(OfferProvider::class, new class implements OfferProvider
        {
            public function search(string $query, array $context = []): array
            {
                return [
                    [
                        'store' => 'Known Mart',
                        'title' => 'Nike Dunk Low',
                        'url' => 'https://known.example/dunk',
                        'brand' => 'Nike',
                        'model' => 'Dunk Low',
                        'size' => '10',
                        'price_minor' => 12_000,
                        'shipping_minor' => 0,
                        'shipping_known' => true,
                        'currency' => 'USD',
                        'availability' => 'in_stock',
                    ],
                    [
                        'store' => 'Unknown Mart',
                        'title' => 'Nike Dunk Low',
                        'url' => 'https://unknown.example/dunk',
                        'brand' => 'Nike',
                        'model' => 'Dunk Low',
                        'size' => '10',
                        'price_minor' => 10_000,
                        'shipping_minor' => null,
                        'shipping_known' => false,
                        'currency' => 'USD',
                        'availability' => 'in_stock',
                    ],
                    [
                        'store' => 'Yen Mart',
                        'title' => 'Nike Dunk Low',
                        'url' => 'https://yen.example/dunk',
                        'brand' => 'Nike',
                        'model' => 'Dunk Low',
                        'size' => '10',
                        'price_minor' => 9_000,
                        'shipping_minor' => 0,
                        'shipping_known' => true,
                        'currency' => 'XXX',
                        'availability' => 'in_stock',
                    ],
                    [
                        'store' => 'Api Mart',
                        'title' => 'Nike Dunk Low',
                        'url' => 'https://serpapi.com/search',
                        'brand' => 'Nike',
                        'model' => 'Dunk Low',
                        'size' => '10',
                        'price_minor' => 1_000,
                        'shipping_minor' => 0,
                        'shipping_known' => true,
                        'currency' => 'USD',
                        'availability' => 'in_stock',
                    ],
                ];
            }
        });
        Sanctum::actingAs($this->makeUser());
        $offers = $this->getJson('/api/v1/compare?q=nike%20sneakers&currency=EUR')->assertOk()->json('data.products.0.offers');
        $this->assertSame(['Known Mart', 'Unknown Mart'], array_column($offers, 'store'));
        $this->assertTrue($offers[0]['shipping_known']);
        $this->assertFalse($offers[1]['shipping_known']);
        $this->assertSame('EUR', $offers[0]['currency']);
        $this->assertSame('EUR', $this->getJson('/api/v1/compare?q=nike%20sneakers&currency=EUR')->json('data.currency'));
    }

    public function test_a_wishlist_emails_when_the_best_price_drops(): void
    {
        Mail::fake();
        $user = $this->makeUser();
        $user->forceFill(['password' => 'correct-horse'])->save();
        Sanctum::actingAs($user);
        $saved = $this->postJson('/api/v1/wishlist', ['query' => 'nike sneakers'])->assertCreated()->json('data');
        $this->assertSame(10500, $saved['best_total_minor']);
        $this->assertSame(0, Artisan::call('prices:check-alerts'));
        Mail::assertNothingSent();

        DB::table('wishlists')->where('id', $saved['id'])->update(['best_total_minor' => 20_000]);
        $this->artisan('prices:check-alerts')->assertSuccessful();
        Mail::assertSent(PriceDropAlert::class, fn (PriceDropAlert $mail): bool => $mail->hasTo($user->email) && $mail->totalMinor === 10500);
        $this->assertSame(10500, (int) DB::table('wishlists')->where('id', $saved['id'])->value('best_total_minor'));
    }

    public function test_health_checks_the_database(): void
    {
        $this->getJson('/up')->assertOk()->assertJsonPath('status', 'up');
        config(['session.driver' => 'database']);
        $this->get('/up', ['Accept' => 'text/html'])->assertOk()->assertJsonPath('status', 'up');
    }

    /** @return array<string, string> */
    private function cookieServer(bool $requestedWith): array
    {
        $server = ['HTTP_ACCEPT' => 'application/json'];
        if ($requestedWith) {
            $server['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        }

        return $server;
    }
}

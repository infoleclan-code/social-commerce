<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Mail\PriceDropAlert;
use App\Models\User;
use App\Services\Compare\OfferProvider;
use App\Services\Compare\PriceHistory;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsSocial;
use Tests\TestCase;

final class CompareFeaturesTest extends TestCase
{
    use BuildsSocial;
    use RefreshDatabase;

    private string $secret = 'KZXW6YTBONSWG33OMVZXG33O';

    public function test_history_hint_compares_the_current_price_with_the_recent_low_and_average(): void
    {
        Sanctum::actingAs($this->makeUser());
        $key = PriceHistory::productKey('Nike', 'Dunk Low', '10');
        $this->observe($key, 40, 20_000);
        $chart = $this->getJson('/api/v1/compare/history?brand=Nike&model=Dunk%20Low&size=10&currency=USD&current_minor=20000')
            ->assertOk()
            ->json('data');
        $this->assertSame('learning', $chart['hint']);

        $this->observe($key, 10, 20_000);
        $this->getJson('/api/v1/compare/history?brand=Nike&model=Dunk%20Low&size=10&currency=USD&current_minor=20000')
            ->assertOk()
            ->assertJsonPath('data.hint', 'good_now');
        $this->getJson('/api/v1/compare/history?brand=Nike&model=Dunk%20Low&size=10&currency=USD&current_minor=22000')
            ->assertOk()
            ->assertJsonPath('data.hint', 'wait');

        $this->getJson('/api/v1/compare?q=nike%20sneakers')->assertOk();
        $recorded = $this->getJson('/api/v1/compare/history?brand=Nike&model=Dunk%20Low&size=10&currency=USD')
            ->assertOk()
            ->json('data.points');
        $this->assertNotEmpty($recorded);
    }

    public function test_landed_price_adds_known_tax_once_and_labels_unknown_parts(): void
    {
        $this->app->instance(OfferProvider::class, new class implements OfferProvider
        {
            public function search(string $query, array $context = []): array
            {
                return [
                    [
                        'store' => 'Included Mart', 'title' => 'Nike Dunk Low', 'url' => 'https://included.example/dunk',
                        'brand' => 'Nike', 'model' => 'Dunk Low', 'size' => '10', 'price_minor' => 10_000,
                        'shipping_minor' => 0, 'shipping_known' => true, 'tax_minor' => 0, 'tax_known' => true,
                        'tax_included' => true, 'currency' => 'USD', 'availability' => 'in_stock',
                    ],
                    [
                        'store' => 'Added Mart', 'title' => 'Nike Dunk Low', 'url' => 'https://added.example/dunk',
                        'brand' => 'Nike', 'model' => 'Dunk Low', 'size' => '10', 'price_minor' => 9_000,
                        'shipping_minor' => 500, 'shipping_known' => true, 'tax_minor' => 1_000, 'tax_known' => true,
                        'tax_included' => false, 'currency' => 'USD', 'availability' => 'in_stock',
                    ],
                    [
                        'store' => 'Plain Mart', 'title' => 'Nike Dunk Low', 'url' => 'https://plain.example/dunk',
                        'brand' => 'Nike', 'model' => 'Dunk Low', 'size' => '10', 'price_minor' => 8_000,
                        'shipping_minor' => null, 'shipping_known' => false, 'tax_minor' => null, 'tax_known' => false,
                        'tax_included' => false, 'currency' => 'USD', 'availability' => 'in_stock',
                    ],
                ];
            }
        });
        Sanctum::actingAs($this->makeUser());
        $offers = $this->getJson('/api/v1/compare?q=nike')->assertOk()->json('data.products.0.offers');
        $byStore = array_column($offers, null, 'store');
        $this->assertSame(10_000, $byStore['Included Mart']['total_minor']);
        $this->assertTrue($byStore['Included Mart']['tax_included']);
        $this->assertSame(10_500, $byStore['Added Mart']['total_minor']);
        $this->assertSame(1_000, $byStore['Added Mart']['tax_minor']);
        $this->assertSame(500, $byStore['Added Mart']['shipping_minor']);
        $this->assertFalse($byStore['Plain Mart']['shipping_known']);
        $this->assertFalse($byStore['Plain Mart']['tax_known']);
        $this->assertFalse($byStore['Plain Mart']['total_known']);
        $this->assertSame(['Plain Mart', 'Included Mart', 'Added Mart'], array_column($offers, 'store'));
    }

    public function test_trust_coupons_shipping_region_and_a_blocked_store(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);
        $offers = $this->getJson('/api/v1/compare?q=nike&country=DE')->assertOk()->json('data.products.0.offers');
        $byStore = array_column($offers, null, 'store');
        $this->assertSame('trusted', $byStore['Finish Line']['trust']);
        $this->assertSame(4.6, $byStore['Finish Line']['rating']);
        $this->assertSame(2400, $byStore['Finish Line']['review_count']);
        $this->assertTrue($byStore['Finish Line']['ships_to_user']);
        $this->assertSame('mixed', $byStore['Foot Locker']['trust']);
        $this->assertFalse($byStore["Dick's"]['ships_to_user']);
        $finish = $byStore['Finish Line']['id'];

        $this->app['auth']->forgetGuards();
        $token = $this->adminToken();
        $this->withToken($token)->postJson('/api/v1/admin/coupons', [
            'host' => 'www.finishline.com',
            'code' => 'SAVE10',
            'label' => '10% off',
        ])->assertCreated();
        $this->withToken($token)->postJson('/api/v1/admin/stores', [
            'host' => 'footlocker.com',
            'status' => 'flagged',
            'note' => 'Slow refunds',
        ])->assertOk();

        Cache::flush();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);
        $again = $this->getJson('/api/v1/compare?q=nike&country=DE')->assertOk()->json('data.products.0.offers');
        $againByStore = array_column($again, null, 'store');
        $this->assertSame('SAVE10', $againByStore['Finish Line']['coupons'][0]['code']);
        $this->assertSame('caution', $againByStore['Foot Locker']['trust']);

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/v1/admin/stores', [
            'host' => 'finishline.com',
            'status' => 'blocked',
        ])->assertOk();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/compare/clicks', ['offer_id' => $finish])->assertNotFound()->assertJsonPath('code', 'OFFER_EXPIRED');
        Cache::flush();
        $blocked = $this->getJson('/api/v1/compare?q=nike')->assertOk()->json('data.products.0.offers');
        $this->assertNotContains('Finish Line', array_column($blocked, 'store'));
    }

    public function test_country_is_passed_to_the_provider_and_shop_settings_stick(): void
    {
        $this->app->instance(OfferProvider::class, new class implements OfferProvider
        {
            public function search(string $query, array $context = []): array
            {
                Cache::put('seen-context', $context, 60);

                return [[
                    'store' => 'Known Mart', 'title' => 'Nike Dunk Low', 'url' => 'https://known.example/dunk',
                    'brand' => 'Nike', 'model' => 'Dunk Low', 'size' => '10', 'price_minor' => 10_000,
                    'shipping_minor' => 0, 'shipping_known' => true, 'currency' => 'USD', 'availability' => 'in_stock',
                    'ships_to' => ['DE'],
                ]];
            }
        });
        Sanctum::actingAs($this->makeUser());
        $this->getJson('/api/v1/shop', ['CF-IPCountry' => 'DE'])
            ->assertOk()
            ->assertJsonPath('data.detected_country', 'DE')
            ->assertJsonPath('data.country', 'DE')
            ->assertJsonPath('data.source', 'detected')
            ->assertJsonPath('data.currency', 'EUR');
        $this->putJson('/api/v1/shop', ['country' => 'GB', 'currency' => 'GBP'])
            ->assertOk()
            ->assertJsonPath('data.country', 'GB')
            ->assertJsonPath('data.currency', 'GBP')
            ->assertJsonPath('data.source', 'saved');
        $this->putJson('/api/v1/shop', ['country' => 'XX', 'currency' => 'USD'])->assertStatus(422);
        $this->getJson('/api/v1/me')->assertOk()->assertJsonMissingPath('data.shop_country');

        $this->getJson('/api/v1/compare?q=nike&country=DE')->assertOk()->assertJsonPath('data.country', 'DE');
        $this->assertSame('de', Cache::get('seen-context')['gl']);
        $this->assertSame('de', Cache::get('seen-context')['hl']);
        $this->getJson('/api/v1/compare?q=nike&currency=JPY')->assertStatus(422);
    }

    public function test_a_refreshed_fx_rate_is_used_and_a_missing_currency_is_still_dropped(): void
    {
        Http::fake([
            'api.frankfurter.dev/*' => Http::response(['rates' => ['EUR' => 0.8]], 200),
        ]);
        $this->artisan('fx:refresh')->assertSuccessful();
        $this->assertSame(800_000, (int) DB::table('fx_rates')->where('currency', 'EUR')->value('per_usd'));

        Sanctum::actingAs($this->makeUser());
        $this->getJson('/api/v1/compare?q=nike&currency=EUR')
            ->assertOk()
            ->assertJsonPath('data.products.0.offers.0.total_minor', 8400);

        Http::fake(['*' => Http::response('no', 500)]);
        $this->artisan('fx:refresh')->assertSuccessful();
        $this->assertSame(800_000, (int) DB::table('fx_rates')->where('currency', 'EUR')->value('per_usd'));
    }

    public function test_a_currency_missing_from_the_rate_table_is_dropped(): void
    {
        $this->app->instance(OfferProvider::class, new class implements OfferProvider
        {
            public function search(string $query, array $context = []): array
            {
                return [
                    [
                        'store' => 'Known Mart', 'title' => 'Nike Dunk Low', 'url' => 'https://known.example/dunk',
                        'brand' => 'Nike', 'model' => 'Dunk Low', 'size' => '10', 'price_minor' => 10_000,
                        'shipping_minor' => 0, 'shipping_known' => true, 'currency' => 'USD', 'availability' => 'in_stock',
                    ],
                    [
                        'store' => 'Yen Mart', 'title' => 'Nike Dunk Low', 'url' => 'https://yen.example/dunk',
                        'brand' => 'Nike', 'model' => 'Dunk Low', 'size' => '10', 'price_minor' => 9_000,
                        'shipping_minor' => 0, 'shipping_known' => true, 'currency' => 'JPY', 'availability' => 'in_stock',
                    ],
                ];
            }
        });
        Sanctum::actingAs($this->makeUser());
        $offers = $this->getJson('/api/v1/compare?q=nike&currency=EUR')->assertOk()->json('data.products.0.offers');
        $this->assertSame(['Known Mart'], array_column($offers, 'store'));
    }

    public function test_a_photo_search_uses_the_same_top_three_flow(): void
    {
        Sanctum::actingAs($this->makeUser());
        $image = imagecreatetruecolor(16, 16);
        ob_start();
        imagejpeg($image, null, 80);
        $bytes = (string) ob_get_clean();
        $this->post('/api/v1/compare/visual', [
            'image' => UploadedFile::fake()->createWithContent('photo.jpg', $bytes),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.detected_query', 'nike sneakers')
            ->assertJsonPath('data.products.0.offers.0.store', 'Finish Line');

        $this->post('/api/v1/compare/visual', [
            'image' => UploadedFile::fake()->createWithContent('notes.txt', 'not an image'),
        ], ['Accept' => 'application/json'])->assertStatus(503)->assertJsonPath('code', 'COMPARE_UNAVAILABLE');
    }

    public function test_a_target_price_pushes_once_and_the_snapshot_records_prices(): void
    {
        Mail::fake();
        Http::fake(['exp.host/*' => Http::response(['data' => ['status' => 'ok']], 200)]);
        $user = $this->makeUser();
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/devices', [
            'platform' => 'ios',
            'expo_push_token' => 'ExponentPushToken[abc123xyz]',
        ])->assertCreated();
        $id = (string) Str::uuid7();
        DB::table('wishlists')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'query' => 'nike sneakers',
            'brand' => null,
            'size' => null,
            'currency' => 'USD',
            'country' => 'US',
            'target_minor' => 11_000,
            'best_total_minor' => null,
            'notify' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('prices:check-alerts')->assertSuccessful();
        Mail::assertSent(PriceDropAlert::class, fn (PriceDropAlert $mail): bool => $mail->reason === 'target' && $mail->totalMinor === 10500);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'exp.host') && $request['title'] === 'Target price');

        Mail::fake();
        Http::fake();
        $this->artisan('prices:check-alerts')->assertSuccessful();
        Mail::assertNothingSent();

        $this->artisan('prices:snapshot')->assertSuccessful();
        $this->assertGreaterThan(0, DB::table('price_observations')->count());
    }

    private function observe(string $key, int $daysAgo, int $total): void
    {
        DB::table('price_observations')->insert([
            'id' => (string) Str::uuid7(),
            'product_key' => $key,
            'query' => 'nike sneakers',
            'store' => 'Finish Line',
            'host' => 'finishline.com',
            'currency' => 'USD',
            'price_minor' => $total,
            'shipping_minor' => 0,
            'tax_minor' => null,
            'total_minor' => $total,
            'observed_on' => now()->subDays($daysAgo)->toDateString(),
            'created_at' => now()->subDays($daysAgo),
        ]);
    }

    private function adminToken(): string
    {
        $user = User::query()->whereRaw('email = ?', ['admin@example.com'])->first()
            ?? $this->makeUser(['email' => 'admin@example.com', 'username' => 'demo_admin', 'password' => 'correct-horse']);
        $user->forceFill([
            'role' => UserRole::Admin,
            'password' => 'correct-horse',
            'totp_secret' => Crypt::encryptString($this->secret),
            'totp_last_step' => null,
        ])->save();

        return (string) $this->postJson('/api/v1/admin/login', [
            'email' => 'admin@example.com',
            'password' => 'correct-horse',
            'otp' => Totp::code($this->secret, intdiv(time(), 30)),
        ])->assertOk()->json('data.token');
    }
}

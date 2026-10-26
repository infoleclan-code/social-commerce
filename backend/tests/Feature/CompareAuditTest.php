<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\PriceDropAlert;
use App\Models\User;
use App\Services\Assistant\LlmClient;
use App\Services\Compare\CouponCatalog;
use App\Services\Compare\PriceHistory;
use App\Services\Compare\StoreDirectory;
use Database\Seeders\CompareDemoSeeder;
use Database\Seeders\DemoAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\Support\BuildsSocial;
use Tests\TestCase;

/** Regressions from the audit of the shopping guide. */
final class CompareAuditTest extends TestCase
{
    use BuildsSocial;
    use RefreshDatabase;

    public function test_japan_drops_stores_known_not_to_ship_there(): void
    {
        Sanctum::actingAs($this->makeUser());
        $stores = array_column(
            $this->getJson('/api/v1/compare?q=nike%20sneakers&country=JP')->assertOk()->json('data.products.0.offers'),
            'store',
        );
        $this->assertContains('Nike', $stores);
        $this->assertNotContains('Finish Line', $stores);
        $this->assertNotContains("Dick's", $stores);
        $this->assertNotContains('Foot Locker', $stores);
    }

    public function test_a_percent_coupon_reduces_the_item_and_other_coupons_stay_aside(): void
    {
        app(CouponCatalog::class)->create('finishline.com', 'SAVE10', '10% off', null);
        app(CouponCatalog::class)->create('finishline.com', 'NOTE', 'Free socks', null);
        Sanctum::actingAs($this->makeUser());
        $finish = array_column(
            $this->getJson('/api/v1/compare?q=nike%20sneakers')->assertOk()->json('data.products.0.offers'),
            null,
            'store',
        )['Finish Line'];
        $this->assertSame(9450, $finish['price_minor']);
        $this->assertSame(9450, $finish['total_minor']);
        $byCode = array_column($finish['coupons'], null, 'code');
        $this->assertTrue($byCode['SAVE10']['included_in_total']);
        $this->assertFalse($byCode['NOTE']['included_in_total']);
    }

    public function test_a_repeat_click_counts_the_savings_once(): void
    {
        Sanctum::actingAs($this->makeUser());
        $offer = $this->getJson('/api/v1/compare?q=nike%20sneakers')->assertOk()->json('data.products.0.offers.0');
        $this->postJson('/api/v1/compare/clicks', ['offer_id' => $offer['id']])->assertOk();
        $this->postJson('/api/v1/compare/clicks', ['offer_id' => $offer['id']])->assertOk();
        $summary = $this->getJson('/api/v1/savings')->assertOk()->json('data');
        $this->assertSame(1, $summary['clicks']);
        $this->assertSame(1300, $summary['saved_minor']);
        $this->assertSame(2, $summary['minor_unit']);
    }

    public function test_a_yen_badge_uses_the_currency_minor_unit(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);
        DB::table('savings_events')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $user->id,
            'offer_key' => str_repeat('b', 24),
            'store' => 'Sony',
            'currency' => 'JPY',
            'paid_minor' => 100,
            'highest_minor' => 110,
            'saved_minor' => 10,
            'created_at' => now(),
        ]);
        $summary = $this->getJson('/api/v1/savings')->assertOk()->json('data');
        $this->assertSame('JPY', $summary['currency']);
        $this->assertSame(0, $summary['minor_unit']);
        $labels = array_column($summary['badges'], 'label', 'id');
        $this->assertSame('Saved 10', $labels['saver']);
        $this->assertStringNotContainsString('dollar', strtolower($labels['saver']));
    }

    public function test_a_shared_list_reports_the_currency_minor_unit(): void
    {
        Sanctum::actingAs($this->makeUser());
        $this->postJson('/api/v1/shared-lists', ['title' => 'Trip', 'currency' => 'JPY'])
            ->assertCreated()
            ->assertJsonPath('data.minor_unit', 0);
    }

    public function test_a_failed_price_alert_does_not_store_the_new_low(): void
    {
        Event::listen(MessageSending::class, function (): void {
            throw new RuntimeException('smtp down');
        });
        Http::fake();
        $user = $this->makeUser();
        $id = (string) Str::uuid7();
        DB::table('wishlists')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'query' => 'nike sneakers',
            'brand' => null,
            'size' => null,
            'currency' => 'USD',
            'country' => 'US',
            'target_minor' => null,
            'best_total_minor' => 20_000,
            'notify' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->artisan('prices:check-alerts')->assertSuccessful();
        $this->assertSame(20_000, (int) DB::table('wishlists')->where('id', $id)->value('best_total_minor'));
        Http::assertNothingSent();
    }

    public function test_a_yen_alert_is_formatted_without_cents(): void
    {
        Mail::fake();
        Http::fake(['exp.host/*' => Http::response(['data' => ['status' => 'ok']], 200)]);
        $user = $this->makeUser();
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/devices', [
            'platform' => 'ios',
            'expo_push_token' => 'ExponentPushToken[yenalerttoken01]',
        ])->assertCreated();
        DB::table('wishlists')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $user->id,
            'query' => 'nike sneakers',
            'brand' => null,
            'size' => null,
            'currency' => 'JPY',
            'country' => 'US',
            'target_minor' => 20_000,
            'best_total_minor' => null,
            'notify' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->artisan('prices:check-alerts')->assertSuccessful();
        Mail::assertSent(PriceDropAlert::class, function (PriceDropAlert $mail) use ($user): bool {
            $html = $mail->render();

            return $mail->hasTo($user->email)
                && $mail->exponent === 0
                && str_contains($html, 'JPY 15855')
                && ! str_contains($html, '158.55');
        });
        Http::assertSent(fn ($request): bool => str_contains((string) $request['body'], 'JPY 15855') && ! str_contains((string) $request['body'], '158.55'));
    }

    public function test_headphones_suggest_a_cheaper_brand_in_the_same_category(): void
    {
        Sanctum::actingAs($this->makeUser());
        $alt = $this->getJson('/api/v1/compare/alternatives?q=sony%20headphones')->assertOk()->json('data');
        $this->assertSame('Bose', $alt['alternatives'][0]['brand']);
        $this->assertSame(7000, $alt['alternatives'][0]['savings_minor']);
    }

    public function test_legacy_try_on_and_cart_hide_stack_traces_when_purchases_are_off(): void
    {
        config(['app.debug' => true, 'commerce.purchases_enabled' => false]);
        $tryOn = $this->postJson('/api/v1/try-on', []);
        $tryOn->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
        $this->assertStringNotContainsString('NotFoundHttpException', $tryOn->getContent());
        $this->assertStringNotContainsString('trace', $tryOn->getContent());
        $cart = $this->getJson('/api/v1/cart');
        $cart->assertNotFound();
        $this->assertStringNotContainsString('/workspace', $cart->getContent());
        $this->assertStringNotContainsString('trace', $cart->getContent());

        Sanctum::actingAs($this->makeUser());
        $this->getJson('/api/v1/compare/try-on?category=shoes')->assertOk()->assertJsonPath('data.mode', 'camera_overlay');
    }

    public function test_a_link_rejects_userinfo_and_private_targets(): void
    {
        $image = imagecreatetruecolor(16, 16);
        ob_start();
        imagejpeg($image, null, 80);
        $bytes = (string) ob_get_clean();
        Http::fake([
            'https://www.youtube.com/redir' => Http::response('', 302, ['Location' => 'https://127.0.0.1/secret']),
            'https://www.youtube.com/decimal' => Http::response('<html><meta property="og:image" content="https://2130706433/a.jpg"></html>', 200),
            'https://i.ytimg.com/*' => Http::response($bytes, 200, ['Content-Type' => 'image/jpeg']),
        ]);
        Sanctum::actingAs($this->makeUser());
        $this->postJson('/api/v1/compare/link', ['url' => 'https://user:pass@www.youtube.com/watch?v=abc'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'LINK_REJECTED');
        $this->postJson('/api/v1/compare/link', ['url' => 'https://www.youtube.com/redir'])
            ->assertStatus(503)
            ->assertJsonPath('code', 'LINK_UNAVAILABLE');
        $this->postJson('/api/v1/compare/link', ['url' => 'https://www.youtube.com/decimal'])
            ->assertStatus(503)
            ->assertJsonPath('code', 'LINK_UNAVAILABLE');
    }

    public function test_a_dollar_budget_from_the_model_is_scaled_into_cents(): void
    {
        $this->app->instance(LlmClient::class, new class implements LlmClient
        {
            public function complete(string $system, string $user): string
            {
                Cache::put('assistant-system', $system, 60);

                return json_encode([
                    'reply' => 'These match that request. The prices are the latest check, not a promise.',
                    'products' => [[
                        'query' => 'nike sneakers',
                        'brand' => 'Nike',
                        'summary' => 'A cushioned court sneaker.',
                        'pros' => ['Widely stocked'],
                        'cons' => ['Popular sizes sell out'],
                        'max_minor' => 106,
                    ]],
                ], JSON_THROW_ON_ERROR);
            }
        });
        Sanctum::actingAs($this->makeUser());
        $offers = $this->postJson('/api/v1/assistant', ['message' => 'running shoes under $106'])->assertOk()->json('data');
        $this->assertStringContainsString('minor units', Cache::get('assistant-system'));
        $this->assertSame(['Finish Line'], array_column($offers['products'][0]['compare']['products'][0]['offers'], 'store'));
    }

    public function test_an_invalid_budget_is_ignored_and_yen_is_not_scaled_by_one_hundred(): void
    {
        $this->app->instance(LlmClient::class, new class implements LlmClient
        {
            public function complete(string $system, string $user): string
            {
                Cache::put('assistant-system', $system, 60);

                return json_encode([
                    'reply' => 'These match that request. The prices are the latest check, not a promise.',
                    'products' => [[
                        'query' => 'nike sneakers',
                        'summary' => 'A cushioned court sneaker.',
                        'pros' => ['Widely stocked'],
                        'cons' => ['Popular sizes sell out'],
                        'max_minor' => -1,
                    ]],
                ], JSON_THROW_ON_ERROR);
            }
        });
        Sanctum::actingAs($this->makeUser());
        $this->postJson('/api/v1/assistant', ['message' => 'nike sneakers', 'currency' => 'JPY'])
            ->assertOk()
            ->assertJsonPath('data.products.0.compare.products.0.offers.0.store', 'Finish Line');
        $this->assertStringContainsString('JPY', Cache::get('assistant-system'));
        $this->assertStringContainsString('no minor unit', Cache::get('assistant-system'));
    }

    public function test_compare_country_must_be_one_we_list(): void
    {
        Sanctum::actingAs($this->makeUser());
        $this->getJson('/api/v1/compare?q=nike&country=ZZ')->assertStatus(422);
        $this->getJson('/api/v1/shop', ['CF-IPCountry' => 'ZZ'])
            ->assertOk()
            ->assertJsonPath('data.detected_country', 'US');
        $this->assertContains('JPY', $this->getJson('/api/v1/shop')->json('data.currencies'));
    }

    public function test_a_search_and_a_store_rating_survive_a_unique_collision(): void
    {
        $plantedSearch = false;
        DB::listen(function ($query) use (&$plantedSearch): void {
            if ($plantedSearch || ! str_contains(strtolower($query->sql), 'compare_searches') || ! str_contains(strtolower($query->sql), 'select')) {
                return;
            }
            $plantedSearch = true;
            DB::table('compare_searches')->insert([
                'id' => (string) Str::uuid7(),
                'query' => 'nike sneakers',
                'country' => 'US',
                'currency' => 'USD',
                'last_searched_at' => now()->subDay(),
            ]);
        });
        app(PriceHistory::class)->rememberSearch('nike sneakers', 'US', 'USD');
        $this->assertSame(1, DB::table('compare_searches')->count());

        $plantedStore = false;
        DB::listen(function ($query) use (&$plantedStore): void {
            if ($plantedStore || ! str_contains(strtolower($query->sql), 'store_reputations') || ! str_contains(strtolower($query->sql), 'select')) {
                return;
            }
            $plantedStore = true;
            DB::table('store_reputations')->insert([
                'host' => 'race.example',
                'rating' => 1,
                'review_count' => 1,
                'status' => 'flagged',
                'updated_at' => now(),
            ]);
        });
        $described = app(StoreDirectory::class)->describe('race.example', ['rating' => 4.8, 'review_count' => 200]);
        $this->assertSame('caution', $described['trust']);
        $this->assertSame(1, DB::table('store_reputations')->where('host', 'race.example')->count());
    }

    public function test_demo_accounts_and_the_price_chart_are_not_seeded_outside_local(): void
    {
        $this->seed(DemoAccountsSeeder::class);
        $this->seed(CompareDemoSeeder::class);
        $this->assertSame(0, User::query()->whereRaw('email = ?', ['buyer@example.com'])->count());
        $this->assertSame(0, DB::table('price_observations')->count());

        $this->app['env'] = 'local';
        $this->seed(CompareDemoSeeder::class);
        $this->assertGreaterThan(0, DB::table('price_observations')->count());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Compare\PriceHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsSocial;
use Tests\TestCase;

final class CompareGuideTest extends TestCase
{
    use BuildsSocial;
    use RefreshDatabase;

    public function test_the_assistant_returns_a_note_and_the_top_offers_within_a_budget(): void
    {
        Sanctum::actingAs($this->makeUser());
        $offers = $this->postJson('/api/v1/assistant', ['message' => 'running shoes under $106'])
            ->assertOk()
            ->json('data');
        $this->assertStringContainsString('latest check', $offers['reply']);
        $this->assertSame(['Finish Line'], array_column($offers['products'][0]['compare']['products'][0]['offers'], 'store'));
        $this->assertNotEmpty($offers['products'][0]['pros']);
        $this->assertNotEmpty($offers['products'][0]['cons']);
    }

    public function test_the_assistant_is_rate_limited(): void
    {
        Sanctum::actingAs($this->makeUser());
        for ($i = 0; $i < 8; $i++) {
            $this->postJson('/api/v1/assistant', ['message' => 'nike sneakers'])->assertOk();
        }
        $this->postJson('/api/v1/assistant', ['message' => 'nike sneakers'])->assertStatus(429)->assertJsonPath('code', 'RATE_LIMITED');
    }

    public function test_an_http_provider_without_a_key_does_not_fall_back_to_fake(): void
    {
        config(['llm.provider' => 'http', 'llm.api_key' => '']);
        Sanctum::actingAs($this->makeUser());
        $this->postJson('/api/v1/assistant', ['message' => 'nike sneakers'])->assertStatus(503)->assertJsonPath('code', 'ASSISTANT_UNAVAILABLE');
    }

    public function test_an_http_model_reply_is_turned_into_the_same_compare_flow(): void
    {
        config([
            'llm.provider' => 'http',
            'llm.api_key' => 'test-key',
            'llm.url' => 'https://api.openai.com/v1/chat/completions',
        ]);
        Http::fake([
            'https://api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'reply' => 'From the model.',
                            'products' => [[
                                'query' => 'nike sneakers',
                                'brand' => 'Nike',
                                'summary' => 'Court shoe.',
                                'pros' => ['Light'],
                                'cons' => ['Narrow'],
                                'max_minor' => null,
                            ]],
                        ]),
                    ],
                ]],
            ]),
        ]);
        Sanctum::actingAs($this->makeUser());
        $this->postJson('/api/v1/assistant', ['message' => 'something casual for the weekend'])
            ->assertOk()
            ->assertJsonPath('data.reply', 'From the model.')
            ->assertJsonPath('data.products.0.compare.products.0.offers.0.store', 'Finish Line');
    }

    public function test_the_forecast_is_labeled_as_an_estimate(): void
    {
        Carbon::setTestNow('2026-10-26 12:00:00');
        try {
            Sanctum::actingAs($this->makeUser());
            $key = PriceHistory::productKey('Nike', 'Dunk Low', '10');
            $this->observe($key, 12, 18_000);
            $this->observe($key, 4, 20_000);
            $forecast = $this->getJson('/api/v1/compare/forecast?brand=Nike&model=Dunk%20Low&size=10&currency=USD&current_minor=22000')
                ->assertOk()
                ->json('data');
            $this->assertTrue($forecast['estimate']);
            $this->assertSame('wait', $forecast['action']);
            $this->assertSame('low', $forecast['confidence']);
            $this->assertSame('Singles Day', $forecast['next_event']['name']);
            $this->assertSame(10, $forecast['expected_drop_percent']);
            $this->assertSame(2200, $forecast['expected_drop_minor']);
            $this->assertStringContainsString('estimate', $forecast['disclaimer']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_a_video_link_uses_the_thumbnail_and_a_bad_host_is_rejected(): void
    {
        $image = imagecreatetruecolor(16, 16);
        ob_start();
        imagejpeg($image, null, 80);
        $bytes = (string) ob_get_clean();
        Http::fake([
            'https://www.youtube.com/missing' => Http::response('<html>no image</html>', 200),
            'https://www.youtube.com/*' => Http::response('<html><meta property="og:image" content="https://i.ytimg.com/vi/abc/hqdefault.jpg"></html>', 200),
            'https://i.ytimg.com/*' => Http::response($bytes, 200, ['Content-Type' => 'image/jpeg']),
        ]);
        Sanctum::actingAs($this->makeUser());
        $this->postJson('/api/v1/compare/link', ['url' => 'https://www.youtube.com/watch?v=abc'])
            ->assertOk()
            ->assertJsonPath('data.detected_query', 'nike sneakers')
            ->assertJsonPath('data.products.0.offers.0.store', 'Finish Line')
            ->assertJsonPath('data.source_host', 'www.youtube.com');
        $this->postJson('/api/v1/compare/link', ['url' => 'https://example.com/watch'])->assertStatus(422)->assertJsonPath('code', 'LINK_REJECTED');
        $this->postJson('/api/v1/compare/link', ['url' => 'https://www.youtube.com/missing'])
            ->assertStatus(503)
            ->assertJsonPath('code', 'LINK_UNAVAILABLE');
    }

    public function test_try_on_is_a_camera_guide_for_shoes_and_a_still_for_everything_else(): void
    {
        Sanctum::actingAs($this->makeUser());
        $this->getJson('/api/v1/compare/try-on?category=shoes')
            ->assertOk()
            ->assertJsonPath('data.supported', true)
            ->assertJsonPath('data.mode', 'camera_overlay');
        $this->getJson('/api/v1/compare/try-on?category=glasses')->assertOk()->assertJsonPath('data.supported', true);
        $this->getJson('/api/v1/compare/try-on?category=hats')
            ->assertOk()
            ->assertJsonPath('data.supported', false)
            ->assertJsonPath('data.mode', 'image');
    }

    public function test_friends_can_vote_and_tally_a_pledge_without_a_payment(): void
    {
        $owner = $this->makeUser();
        $friend = $this->makeUser();
        Sanctum::actingAs($owner);
        $created = $this->postJson('/api/v1/shared-lists', ['title' => 'Birthday sneakers'])->assertCreated()->json('data');
        $this->postJson('/api/v1/shared-lists/'.$created['id'].'/items', ['query' => 'nike sneakers'])->assertCreated();
        $this->assertSame('not_processed', $created['payment']);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($friend);
        $joined = $this->postJson('/api/v1/shared-lists/join', ['token' => $created['token']])->assertOk()->json('data');
        $item = $joined['items'][0]['id'];
        $this->postJson('/api/v1/shared-lists/'.$created['id'].'/votes', ['item_id' => $item, 'vote' => 'up'])->assertOk();
        $tallied = $this->postJson('/api/v1/shared-lists/'.$created['id'].'/pledges', [
            'amount_minor' => 2500,
            'note' => 'from me',
        ])->assertCreated()->json('data');
        $this->assertSame(2500, $tallied['pledge_total_minor']);
        $this->assertSame(1, $tallied['items'][0]['votes_up']);
        $this->assertSame('not_processed', $tallied['payment']);
        $this->assertSame(0, DB::table('orders')->count());
        $this->getJson('/api/v1/shared-lists/'.Str::uuid())->assertNotFound()->assertJsonPath('code', 'LIST_NOT_FOUND');
    }

    public function test_a_cheaper_other_brand_is_suggested_with_the_percent_saved(): void
    {
        Sanctum::actingAs($this->makeUser());
        $alt = $this->getJson('/api/v1/compare/alternatives?q=nike%20sneakers')->assertOk()->json('data.alternatives.0');
        $this->assertSame('adidas samba', $alt['query']);
        $this->assertSame(500, $alt['savings_minor']);
        $this->assertSame(5, $alt['savings_percent']);
        $this->assertStringContainsString('another brand', $alt['quality_note']);
    }

    public function test_opening_a_store_records_savings_against_the_highest_offer(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);
        $offers = $this->getJson('/api/v1/compare?q=nike%20sneakers')->assertOk()->json('data.products.0.offers');
        $this->postJson('/api/v1/compare/clicks', ['offer_id' => $offers[0]['id']])->assertOk();
        $summary = $this->getJson('/api/v1/savings')->assertOk()->json('data');
        $this->assertSame(1300, $summary['saved_minor']);
        $this->assertSame(1, $summary['clicks']);
        $this->assertSame('first_click', $summary['badges'][0]['id']);
    }

    public function test_a_narrower_search_does_not_erase_the_top_three_savings_gap(): void
    {
        Sanctum::actingAs($this->makeUser());
        $offerId = (string) $this->getJson('/api/v1/compare?q=nike%20sneakers')->json('data.products.0.offers.0.id');
        $this->postJson('/api/v1/assistant', ['message' => 'running shoes under $106'])->assertOk();
        $this->postJson('/api/v1/compare/clicks', ['offer_id' => $offerId])->assertOk();
        $this->assertSame(1300, $this->getJson('/api/v1/savings')->json('data.saved_minor'));
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
}

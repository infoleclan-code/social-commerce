<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Commerce\LandedCost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\BuildsSocial;
use Tests\TestCase;

final class GrowthP1Test extends TestCase
{
    use BuildsSocial;
    use RefreshDatabase;

    public function test_for_you_ranks_a_matching_taste_above_an_equal_post(): void
    {
        $viewer = $this->makeUser();
        $author = $this->makeUser();
        $street = $this->reel($author->id, ['streetwear']);
        $beauty = $this->reel($author->id, ['beauty']);
        Sanctum::actingAs($viewer);
        $this->putJson('/api/v1/taste', ['styles' => ['streetwear']])->assertOk();

        $ids = $this->getJson('/api/v1/feed?mode=for_you')->assertOk()->json('data.*.id');
        $this->assertLessThan(array_search($beauty, $ids, true), array_search($street, $ids, true));
    }

    #[Group('purchases')]
    public function test_landed_price_includes_shipping_vat_and_duty_before_purchase(): void
    {
        $quote = LandedCost::quote((object) [
            'price_minor' => 10000,
            'currency' => 'USD',
            'ships_from' => 'US',
            'shipping_minor' => 500,
            'vat_bps' => 2000,
            'duty_bps' => 500,
            'handling_days' => 2,
        ], 'DE');

        $this->assertSame(500 + 800, $quote['shipping']['amount_minor']);
        $this->assertSame(intdiv(11300 * 2000, 10000), $quote['vat']['amount_minor']);
        $this->assertSame(intdiv(10000 * 500, 10000), $quote['duty']['amount_minor']);
        $this->assertSame(10000 + 1300 + $quote['vat']['amount_minor'] + 500, $quote['total']['amount_minor']);
        $this->assertNotEmpty($quote['delivers_on']);
    }

    #[Group('purchases')]
    public function test_unverified_seller_cannot_sync_a_catalogue(): void
    {
        $seller = $this->makeUser();
        Sanctum::actingAs($seller);
        $this->postJson('/api/v1/seller', [
            'legal_name' => 'Studio LLC',
            'country' => 'US',
            'registration_number' => 'EIN-12',
        ])->assertCreated()->assertJsonPath('data.status', 'pending');

        $this->postJson('/api/v1/seller/catalog', [
            'products' => [[
                'sku' => 'j1',
                'title' => 'Jacket',
                'brand' => 'Atelier',
                'image_url' => 'https://example.com/j.jpg',
                'price_minor' => 1000,
                'stock' => 2,
            ]],
        ])->assertStatus(409);

        $this->postJson('/api/v1/seller/refresh')->assertOk()->assertJsonPath('data.status', 'verified');
        $this->postJson('/api/v1/seller/catalog', [
            'products' => [[
                'sku' => 'j1',
                'title' => 'Jacket',
                'brand' => 'Atelier',
                'image_url' => 'https://example.com/j.jpg',
                'price_minor' => 1000,
                'stock' => 2,
            ]],
        ])->assertOk()->assertJsonPath('data.upserted', 1);
    }

    private function reel(string $authorId, array $tags): string
    {
        $id = (string) Str::uuid7();
        DB::table('feed_posts')->insert([
            'id' => $id,
            'user_id' => $authorId,
            'kind' => 'media',
            'audience' => 'public',
            'media_type' => 'image',
            'media_path' => 'posts/'.$id.'.jpg',
            'tags' => json_encode($tags),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}

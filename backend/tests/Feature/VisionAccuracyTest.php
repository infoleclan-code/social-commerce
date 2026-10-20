<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Media\ImageEmbedding;
use App\Services\Media\StructureEmbedding;
use App\Services\Vision\VisionDescriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsSocial;
use Tests\TestCase;

final class VisionAccuracyTest extends TestCase
{
    use BuildsSocial;
    use RefreshDatabase;

    public function test_orange_slides_are_not_returned_as_red_running_shoes(): void
    {
        $this->assertSame('footwear.slides', \App\Services\Vision\RemoteVisionDescriber::category('Footwear/Slides'));
        $jacketA = $this->garment('Night jacket', 'apparel.jacket', 9600);
        $jacketB = $this->garment('Night jacket', 'apparel.jacket', 10900);
        $jacketC = $this->garment('City coat', 'apparel.coat', 24000);
        $slideA = $this->garment('Nike slides', 'footwear.slides', 3800, 'nike', '{grey,orange}');
        $slideB = $this->garment('Nike slides', 'footwear.slides', 4500, 'nike', '{grey,orange}');
        $slideC = $this->garment('Nike slides', 'footwear.slides', 5200, 'nike', '{grey,orange}');
        $orange = $this->reelImage(11);
        $vectors = $this->vectors($orange);
        $runner = $this->shoe($vectors, 'red', 'footwear.running', 'northline', 6400);
        $this->app->instance(VisionDescriber::class, $this->vision('footwear.slides', ['grey', 'orange'], 'nike', ['orange bubble sole'], 0.96));
        Sanctum::actingAs($this->makeUser());

        $fashion = $this->postJson('/api/v1/visual-search', [
            'image_base64' => base64_encode($orange),
            'category' => 'apparel',
        ])->assertOk();
        $this->assertSame([$slideA, $slideB, $slideC], $fashion->json('data.results.*.product.id'));
        $this->assertNotContains($runner, $fashion->json('data.results.*.product.id'));
        $this->assertNotContains($jacketA, $fashion->json('data.results.*.product.id'));
        $this->assertNotContains($jacketB, $fashion->json('data.results.*.product.id'));
        $this->assertNotContains($jacketC, $fashion->json('data.results.*.product.id'));
        $this->assertGreaterThan(0, $fashion->json('data.results.0.visual_similarity'));
        $this->assertSame('one_tap', $fashion->json('data.results.0.product.purchase.mode'));
        $this->assertNotNull($fashion->json('data.results.0.landed.shipping'));
    }

    public function test_a_confirmed_running_shoe_is_returned_when_category_and_color_agree(): void
    {
        $red = $this->reelImage(12);
        $vectors = $this->vectors($red);
        $cheap = $this->shoe($vectors, 'red', 'footwear.running', 'northline', 5400);
        $mid = $this->shoe($vectors, 'red', 'footwear.running', 'northline', 7100);
        $high = $this->shoe($vectors, 'red', 'footwear.running', 'northline', 9300);
        $this->app->instance(VisionDescriber::class, $this->vision('footwear.running', ['red'], 'northline', [], 0.91));
        Sanctum::actingAs($this->makeUser());

        $ids = $this->postJson('/api/v1/visual-search', [
            'image_base64' => base64_encode($red),
        ])->assertOk()->json('data.results.*.product.id');

        $this->assertSame([$cheap, $mid, $high], $ids);
    }

    /** @param  array{0: string, 1: string}  $vectors
     * @param  list<string>  $colors
     */
    private function vision(string $category, array $colors, ?string $brand, array $features, float $confidence): VisionDescriber
    {
        return new class($category, $colors, $brand, $features, $confidence) implements VisionDescriber
        {
            /** @param  list<string>  $colors
             * @param  list<string>  $features
             */
            public function __construct(
                private string $category,
                private array $colors,
                private ?string $brand,
                private array $features,
                private float $confidence,
            ) {}

            public function describe(string $bytes): array
            {
                return [
                    'category' => $this->category,
                    'colors' => $this->colors,
                    'brand' => $this->brand,
                    'features' => $this->features,
                    'confidence' => $this->confidence,
                    'source' => 'test',
                ];
            }
        };
    }

    private function garment(string $title, string $category, int $price, string $brand = 'atelier', string $colors = '{black}'): string
    {
        $id = (string) Str::uuid7();
        DB::table('products')->insert([
            'id' => $id,
            'title' => $title,
            'brand' => 'Atelier',
            'image_url' => 'https://example.com/garment.jpg',
            'price_minor' => $price,
            'squad_price_minor' => intdiv($price * 70, 100),
            'slug' => 'g-'.str_replace('-', '', $id),
            'currency' => 'USD',
            'stock' => 6,
            'ships_from' => 'US',
            'shipping_minor' => 500,
            'vision_category' => $category,
            'vision_colors' => $colors,
            'vision_brand' => $brand,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /** @param  array{0: string, 1: string}  $vectors */
    private function shoe(array $vectors, string $color, string $category, string $brand, int $price): string
    {
        $id = (string) Str::uuid7();
        DB::table('products')->insert([
            'id' => $id,
            'title' => 'Court runner',
            'brand' => ucfirst($brand),
            'image_url' => 'https://example.com/shoe.jpg',
            'price_minor' => $price,
            'squad_price_minor' => intdiv($price * 70, 100),
            'slug' => 'shoe-'.str_replace('-', '', $id),
            'currency' => 'USD',
            'stock' => 4,
            'ships_from' => 'US',
            'shipping_minor' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('products')->where('id', $id)->update([
            'embedding' => $vectors[0],
            'structure_embedding' => $vectors[1],
            'vision_category' => $category,
            'vision_colors' => '{'.$color.'}',
            'vision_brand' => $brand,
        ]);

        return $id;
    }

    /** @return array{0: string, 1: string} */
    private function vectors(string $jpeg): array
    {
        return [
            ImageEmbedding::literal(ImageEmbedding::fromBytes($jpeg)),
            ImageEmbedding::literal(StructureEmbedding::fromBytes($jpeg)),
        ];
    }

    private function swatch(int $red, int $green, int $blue): string
    {
        $image = imagecreatetruecolor(32, 32);
        imagefill($image, 0, 0, imagecolorallocate($image, $red, $green, $blue));
        ob_start();
        imagejpeg($image, null, 90);
        imagedestroy($image);

        return (string) ob_get_clean();
    }
}

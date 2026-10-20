<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\Media\ColorPalette;
use App\Services\Media\ImageEmbedding;
use App\Services\Media\StructureEmbedding;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Fifty shoppable demo products. Existing slugs are left as they are. */
class DemoCatalogSeed extends Seeder
{
    public function run(): void
    {
        $added = 0;
        foreach ($this->items() as $index => $item) {
            $slug = sprintf('demo-%02d-%s', $index + 1, Str::slug($item['title']));
            if (DB::table('products')->where('slug', $slug)->exists()) {
                continue;
            }
            $bytes = $this->image($item['photo'], $item['color']);
            $price = $item['price_minor'];
            DB::table('products')->insert([
                'id' => (string) Str::uuid7(),
                'title' => $item['title'],
                'brand' => $item['brand'],
                'image_url' => $item['photo'],
                'price_minor' => $price,
                'squad_price_minor' => intdiv($price * 70, 100),
                'slug' => $slug,
                'currency' => 'USD',
                'stock' => $item['stock'],
                'ships_from' => $item['ships_from'],
                'shipping_minor' => $item['shipping_minor'],
                'vat_bps' => 2000,
                'duty_bps' => $item['duty_bps'],
                'handling_days' => 2,
                'try_on_enabled' => $item['category'] !== 'apparel.scarf',
                'embedding' => ImageEmbedding::literal(ImageEmbedding::fromBytes($bytes)),
                'structure_embedding' => ImageEmbedding::literal(StructureEmbedding::fromBytes($bytes)),
                'vision_category' => $item['category'],
                'vision_colors' => ColorPalette::literal(ColorPalette::dominant($bytes)),
                'vision_brand' => strtolower($item['brand']),
                'vision_features' => '{'.$item['feature'].'}',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $added++;
        }
        $this->command?->info('Added '.$added.' demo products. Catalog now has '.DB::table('products')->count().'.');
    }

    /** @return list<array{title: string, brand: string, category: string, price_minor: int, shipping_minor: int, duty_bps: int, ships_from: string, stock: int, feature: string, color: array{int, int, int}, photo: string}> */
    private function items(): array
    {
        $photos = [
            'https://images.unsplash.com/photo-1603487742131-4160ec999306?auto=format&fit=crop&w=800&q=70',
            'https://images.unsplash.com/photo-1549298916-b41d501d3772?auto=format&fit=crop&w=800&q=70',
            'https://images.unsplash.com/photo-1542291026-7eec264c27ff?auto=format&fit=crop&w=800&q=70',
            'https://images.unsplash.com/photo-1529139574466-a303027c1d8b?auto=format&fit=crop&w=800&q=70',
            'https://images.unsplash.com/photo-1562183241-b937e95585b6?auto=format&fit=crop&w=800&q=70',
            'https://images.unsplash.com/photo-1595950653106-6c9ebd614d3a?auto=format&fit=crop&w=800&q=70',
            'https://images.unsplash.com/photo-1606107557195-0e29a4b5b4aa?auto=format&fit=crop&w=800&q=70',
            'https://images.unsplash.com/photo-1552346154-21d32810aba3?auto=format&fit=crop&w=800&q=70',
            'https://images.unsplash.com/photo-1514989940723-e8e51635b782?auto=format&fit=crop&w=800&q=70',
            'https://images.unsplash.com/photo-1600185365483-26d7a4cc7519?auto=format&fit=crop&w=800&q=70',
        ];
        $rows = [
            ['Nike slides', 'Nike', 'footwear.slides', 'slide', [255, 140, 40]],
            ['Cloud slides', 'Lane', 'footwear.slides', 'slide', [180, 180, 180]],
            ['Pool slides', 'Outlet', 'footwear.slides', 'slide', [40, 40, 40]],
            ['Foam slides', 'Nike', 'footwear.slides', 'slide', [240, 240, 240]],
            ['Sport slides', 'Northline', 'footwear.slides', 'slide', [30, 90, 180]],
            ['Terrace slides', 'Lumen', 'footwear.slides', 'slide', [20, 120, 80]],
            ['Weekend slides', 'Atelier', 'footwear.slides', 'slide', [120, 70, 40]],
            ['Resort slides', 'Nike', 'footwear.slides', 'slide', [200, 40, 40]],
            ['Nike Mind', 'Nike', 'footwear.sneakers', 'sneaker', [230, 120, 20]],
            ['Court low', 'Nike', 'footwear.sneakers', 'sneaker', [245, 245, 245]],
            ['Court high', 'Lane', 'footwear.sneakers', 'sneaker', [20, 20, 20]],
            ['Day one', 'Northline', 'footwear.sneakers', 'sneaker', [40, 80, 160]],
            ['Studio low', 'Atelier', 'footwear.sneakers', 'sneaker', [90, 90, 90]],
            ['Harbor sneaker', 'Lumen', 'footwear.sneakers', 'sneaker', [160, 30, 40]],
            ['Metro sneaker', 'Outlet', 'footwear.sneakers', 'sneaker', [30, 30, 30]],
            ['Field low', 'Nike', 'footwear.sneakers', 'sneaker', [70, 110, 50]],
            ['Park sneaker', 'Lane', 'footwear.sneakers', 'sneaker', [200, 180, 140]],
            ['Night court', 'Atelier', 'footwear.sneakers', 'sneaker', [15, 15, 20]],
            ['City runner', 'Nike', 'footwear.running', 'runner', [220, 30, 30]],
            ['Dawn runner', 'Northline', 'footwear.running', 'runner', [250, 200, 40]],
            ['Track runner', 'Lane', 'footwear.running', 'runner', [20, 20, 20]],
            ['Mile runner', 'Outlet', 'footwear.running', 'runner', [240, 240, 240]],
            ['River runner', 'Lumen', 'footwear.running', 'runner', [20, 90, 170]],
            ['Tempo runner', 'Nike', 'footwear.running', 'runner', [180, 50, 20]],
            ['Easy runner', 'Atelier', 'footwear.running', 'runner', [80, 80, 80]],
            ['Long runner', 'Northline', 'footwear.running', 'runner', [40, 140, 90]],
            ['Night jacket', 'Atelier', 'apparel.jacket', 'jacket', [20, 20, 25]],
            ['City jacket', 'Northline', 'apparel.jacket', 'jacket', [40, 50, 70]],
            ['Rain jacket', 'Lane', 'apparel.jacket', 'jacket', [30, 70, 120]],
            ['Field jacket', 'Lumen', 'apparel.jacket', 'jacket', [90, 70, 40]],
            ['Studio jacket', 'Atelier', 'apparel.jacket', 'jacket', [10, 10, 10]],
            ['Transit jacket', 'Outlet', 'apparel.jacket', 'jacket', [60, 60, 60]],
            ['Harbor jacket', 'Nike', 'apparel.jacket', 'jacket', [20, 40, 30]],
            ['Wool jacket', 'Lane', 'apparel.jacket', 'jacket', [120, 90, 60]],
            ['Line jacket', 'Northline', 'apparel.jacket', 'jacket', [30, 30, 40]],
            ['Soft jacket', 'Lumen', 'apparel.jacket', 'jacket', [200, 190, 180]],
            ['City coat', 'Northline', 'apparel.coat', 'coat', [50, 60, 80]],
            ['Long coat', 'Atelier', 'apparel.coat', 'coat', [25, 25, 30]],
            ['Wool coat', 'Lane', 'apparel.coat', 'coat', [110, 80, 50]],
            ['Belt coat', 'Lumen', 'apparel.coat', 'coat', [70, 40, 30]],
            ['Rain coat', 'Outlet', 'apparel.coat', 'coat', [20, 50, 90]],
            ['Evening coat', 'Atelier', 'apparel.coat', 'coat', [15, 15, 18]],
            ['Travel coat', 'Northline', 'apparel.coat', 'coat', [90, 90, 95]],
            ['Bridge scarf', 'Lumen', 'apparel.scarf', 'scarf', [40, 90, 70]],
            ['Silk scarf', 'Atelier', 'apparel.scarf', 'scarf', [160, 40, 50]],
            ['Wool scarf', 'Lane', 'apparel.scarf', 'scarf', [80, 50, 30]],
            ['Check scarf', 'Northline', 'apparel.scarf', 'scarf', [30, 40, 80]],
            ['Stripe scarf', 'Outlet', 'apparel.scarf', 'scarf', [20, 20, 20]],
            ['Travel scarf', 'Lumen', 'apparel.scarf', 'scarf', [180, 150, 90]],
            ['Evening scarf', 'Atelier', 'apparel.scarf', 'scarf', [40, 20, 50]],
        ];
        $origins = ['US', 'VN', 'CN', 'PT', 'DE'];
        $items = [];
        foreach ($rows as $index => [$title, $brand, $category, $feature, $color]) {
            $items[] = [
                'title' => $title,
                'brand' => $brand,
                'category' => $category,
                'price_minor' => 2400 + ($index * 370),
                'shipping_minor' => 400 + (($index % 5) * 150),
                'duty_bps' => 300 + (($index % 4) * 150),
                'ships_from' => $origins[$index % count($origins)],
                'stock' => 4 + ($index % 9),
                'feature' => $feature,
                'color' => $color,
                'photo' => $photos[$index % count($photos)],
            ];
        }

        return $items;
    }

    /** @param  array{int, int, int}  $color */
    private function image(string $url, array $color): string
    {
        $context = stream_context_create(['http' => ['timeout' => 8]]);
        $bytes = @file_get_contents($url, false, $context);
        if (is_string($bytes) && strlen($bytes) > 1000) {
            return $bytes;
        }
        $canvas = imagecreatetruecolor(320, 400);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, $color[0], $color[1], $color[2]));
        imagefilledrectangle($canvas, 40, 60, 280, 340, imagecolorallocate($canvas, min(255, $color[0] + 30), min(255, $color[1] + 20), $color[2]));
        ob_start();
        imagejpeg($canvas, null, 85);
        imagedestroy($canvas);

        return (string) ob_get_clean();
    }
}

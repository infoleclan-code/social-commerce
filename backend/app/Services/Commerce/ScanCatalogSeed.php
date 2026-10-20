<?php

declare(strict_types=1);

namespace App\Services\Commerce;

use App\Exceptions\ApiException;
use App\Services\Media\ColorPalette;
use App\Services\Media\ImageEmbedding;
use App\Services\Media\ImageFetcher;
use App\Services\Media\ImageFetchFailed;
use App\Services\Media\ImageGuard;
use App\Services\Media\StructureEmbedding;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Fills luminance and structure vectors from each product photo, and adds
 * cheaper seller listings that share that photo so a scan can return three prices.
 *
 * Photos are downloaded with the SSRF-safe fetcher (public https hosts only, size and time caps) and
 * pass ImageGuard before anything decodes them. Each photo is indexed for visual search
 * (product_embeddings, visual_v2) as well as into the older products.embedding columns. Offers that
 * share one photo share a canonical_key, so the copied-photo check treats them as one catalogue
 * product instead of flagging them.
 */
final class ScanCatalogSeed
{
    public function __construct(
        private readonly ImageFetcher $fetcher,
        private readonly CatalogImageIndexer $indexer,
    ) {}

    /** @var list<array{slug: string, title: string, brand: string, price_minor: int, shipping_minor: int}> */
    private const JACKET_OFFERS = [
        ['slug' => 'night-jacket-lane', 'title' => 'Night jacket', 'brand' => 'Lane', 'price_minor' => 10900, 'shipping_minor' => 500],
        ['slug' => 'night-jacket-outlet', 'title' => 'Night jacket', 'brand' => 'Outlet', 'price_minor' => 9600, 'shipping_minor' => 400],
    ];

    /** @var list<array{slug: string, title: string, brand: string, price_minor: int, shipping_minor: int}> */
    private const SHOE_OFFERS = [
        ['slug' => 'court-runner', 'title' => 'Court runner', 'brand' => 'Northline', 'price_minor' => 8800, 'shipping_minor' => 500],
        ['slug' => 'court-runner-lane', 'title' => 'Court runner', 'brand' => 'Lane', 'price_minor' => 6400, 'shipping_minor' => 700],
        ['slug' => 'court-runner-outlet', 'title' => 'Court runner', 'brand' => 'Outlet', 'price_minor' => 5400, 'shipping_minor' => 900],
    ];

    private const SHOE_IMAGE = 'https://images.unsplash.com/photo-1542291026-7eec264c27ff?auto=format&fit=crop&w=800&q=80';

    public function run(): void
    {
        // The base jacket and its seller offers are one catalogue product (they share the photo).
        DB::table('products')->where('slug', 'night-jacket')->update(['canonical_key' => 'seed:night-jacket']);
        foreach (DB::table('products')->whereNotNull('image_url')->orderBy('title')->get(['id', 'image_url']) as $product) {
            $this->storeVectors((string) $product->id, $this->download((string) $product->image_url));
        }

        $jacket = DB::table('products')->where('slug', 'night-jacket')->first();
        if ($jacket !== null) {
            $jacketBytes = $this->download((string) $jacket->image_url);
            foreach (self::JACKET_OFFERS as $offer) {
                $id = $this->upsertOffer($offer + ['canonical_key' => 'seed:night-jacket'], (string) $jacket->image_url, (string) $jacket->embedding, (string) $jacket->structure_embedding);
                $this->index($id, $jacketBytes);
            }
        }

        $shoeBytes = $this->download(self::SHOE_IMAGE);
        $shoeLuma = ImageEmbedding::literal(ImageEmbedding::fromBytes($shoeBytes));
        $shoeStructure = ImageEmbedding::literal(StructureEmbedding::fromBytes($shoeBytes));
        foreach (self::SHOE_OFFERS as $offer) {
            $this->index($this->upsertOffer($offer + ['canonical_key' => 'seed:court-runner'], self::SHOE_IMAGE, $shoeLuma, $shoeStructure), $shoeBytes);
        }
        $this->seedFamily(
            'https://images.unsplash.com/photo-1603487742131-4160ec999306?auto=format&fit=crop&w=800&q=80',
            'footwear.slides',
            [
                ['slug' => 'nike-slides-outlet', 'title' => 'Nike slides', 'brand' => 'Outlet', 'price_minor' => 3800, 'shipping_minor' => 450, 'duty_bps' => 600, 'ships_from' => 'VN'],
                ['slug' => 'nike-slides-lane', 'title' => 'Nike slides', 'brand' => 'Lane', 'price_minor' => 4500, 'shipping_minor' => 700, 'duty_bps' => 400, 'ships_from' => 'US'],
                ['slug' => 'nike-slides', 'title' => 'Nike slides', 'brand' => 'Nike', 'price_minor' => 5200, 'shipping_minor' => 900, 'duty_bps' => 800, 'ships_from' => 'CN'],
            ],
        );
        $this->seedFamily(
            'https://images.unsplash.com/photo-1549298916-b41d501d3772?auto=format&fit=crop&w=800&q=80',
            'footwear.sneakers',
            [
                ['slug' => 'nike-mind-outlet', 'title' => 'Nike Mind', 'brand' => 'Outlet', 'price_minor' => 7400, 'shipping_minor' => 500, 'duty_bps' => 500, 'ships_from' => 'VN'],
                ['slug' => 'nike-mind-lane', 'title' => 'Nike Mind', 'brand' => 'Lane', 'price_minor' => 8900, 'shipping_minor' => 800, 'duty_bps' => 400, 'ships_from' => 'PT'],
                ['slug' => 'nike-mind', 'title' => 'Nike Mind', 'brand' => 'Nike', 'price_minor' => 11200, 'shipping_minor' => 600, 'duty_bps' => 700, 'ships_from' => 'CN'],
            ],
        );
    }

    /** @param  list<array{slug: string, title: string, brand: string, price_minor: int, shipping_minor: int, duty_bps: int, ships_from: string}>  $offers */
    private function seedFamily(string $imageUrl, string $category, array $offers): void
    {
        $bytes = $this->download($imageUrl);
        $luminance = ImageEmbedding::literal(ImageEmbedding::fromBytes($bytes));
        $structure = ImageEmbedding::literal(StructureEmbedding::fromBytes($bytes));
        $colors = ColorPalette::literal(ColorPalette::dominant($bytes));
        $canonical = 'seed:'.Str::slug($offers[0]['title']);
        foreach ($offers as $offer) {
            $this->index($this->upsertOffer($offer + ['canonical_key' => $canonical], $imageUrl, $luminance, $structure, $colors, $category), $bytes);
        }
    }

    /** visual_v2 signature in product_embeddings (what visual search reads), plus the copied-photo check. */
    private function index(string $productId, string $bytes): void
    {
        $this->indexer->indexBytes($productId, $bytes);
    }

    /** @param  array{slug: string, title: string, brand: string, price_minor: int, shipping_minor: int, duty_bps?: int, ships_from?: string, canonical_key?: string}  $offer */
    private function upsertOffer(array $offer, string $imageUrl, string $luminance, string $structure, ?string $colors = null, ?string $category = null): string
    {
        $existing = DB::table('products')->where('slug', $offer['slug'])->value('id');
        $row = [
            'title' => $offer['title'],
            'brand' => $offer['brand'],
            'image_url' => $imageUrl,
            'price_minor' => $offer['price_minor'],
            'squad_price_minor' => intdiv($offer['price_minor'] * 70, 100),
            'shipping_minor' => $offer['shipping_minor'],
            'duty_bps' => $offer['duty_bps'] ?? 400,
            'ships_from' => $offer['ships_from'] ?? 'US',
            'stock' => 8,
            'embedding' => $luminance,
            'structure_embedding' => $structure,
            'vision_category' => $category ?? self::categoryFor($offer['title']),
            'vision_brand' => self::brandFor($offer['title'], $offer['brand']),
            'canonical_key' => $offer['canonical_key'] ?? null,
            'updated_at' => now(),
        ];
        if ($colors !== null) {
            $row['vision_colors'] = $colors;
        }
        if ($existing === null) {
            $id = (string) Str::uuid7();
            DB::table('products')->insert($row + [
                'id' => $id,
                'slug' => $offer['slug'],
                'currency' => 'USD',
                'ships_from' => 'US',
                'created_at' => now(),
            ]);

            return $id;
        }
        DB::table('products')->where('id', $existing)->update($row);

        return (string) $existing;
    }

    private function storeVectors(string $productId, string $bytes): void
    {
        $product = DB::table('products')->where('id', $productId)->first(['title', 'brand']);
        DB::table('products')->where('id', $productId)->update([
            'embedding' => ImageEmbedding::literal(ImageEmbedding::fromBytes($bytes)),
            'structure_embedding' => ImageEmbedding::literal(StructureEmbedding::fromBytes($bytes)),
            'vision_colors' => ColorPalette::literal(ColorPalette::dominant($bytes)),
            'vision_category' => self::categoryFor((string) ($product->title ?? '')),
            'vision_brand' => self::brandFor((string) ($product->title ?? ''), (string) ($product->brand ?? '')),
            'updated_at' => now(),
        ]);
        $this->index($productId, $bytes);
    }

    /** Public https hosts only (no internal addresses, no file paths), then ImageGuard before any decoder. */
    private function download(string $url): string
    {
        try {
            $bytes = $this->fetcher->fetch($url);
            ImageGuard::decode($bytes);
        } catch (ImageFetchFailed|ApiException $failed) {
            throw new RuntimeException('The product photo could not be read: '.$failed->getMessage(), previous: $failed);
        }

        return $bytes;
    }

    private static function categoryFor(string $title): ?string
    {
        $title = strtolower($title);

        return match (true) {
            str_contains($title, 'slide') => 'footwear.slides',
            str_contains($title, 'mind'), str_contains($title, 'sneaker') => 'footwear.sneakers',
            str_contains($title, 'runner'), str_contains($title, 'shoe') => 'footwear.running',
            str_contains($title, 'jacket') => 'apparel.jacket',
            str_contains($title, 'coat') => 'apparel.coat',
            str_contains($title, 'scarf') => 'apparel.scarf',
            default => null,
        };
    }

    private static function brandFor(string $title, string $brand): string
    {
        if (str_contains(strtolower($title), 'nike')) {
            return 'nike';
        }

        return strtolower($brand);
    }
}

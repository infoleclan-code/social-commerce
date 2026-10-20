<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Exceptions\ApiException;
use App\Services\Commerce\AutoTagService;
use App\Services\Commerce\CatalogImageIndexer;
use App\Services\Media\FakeImageFetcher;
use App\Services\Media\ImageFetcher;
use App\Services\Media\VisualEmbedding;
use App\Services\Search\VectorStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsCommerce;
use Tests\Support\BuildsSocial;
use Tests\Support\HostileImages;
use Tests\TestCase;

/**
 * AutoTagService decisions, and product spoofing: copied photos, unverified sellers and hostile
 * catalogue URLs never reach visual search, auto-tagging or checkout.
 */
final class AutoTagAndSpoofingTest extends TestCase
{
    use BuildsCommerce;
    use BuildsSocial;
    use RefreshDatabase;

    public function test_an_unambiguous_match_attaches_and_repeating_it_is_idempotent(): void
    {
        $frame = $this->reelImage(31);
        $match = $this->indexedProduct($frame);
        $this->indexedProduct($this->reelImage(32));
        $post = $this->reelPost();

        $first = app(AutoTagService::class)->match($post, HostileImages::variant($frame, 600, 70));
        $again = app(AutoTagService::class)->match($post, $frame);

        $this->assertSame($match, $first[0]['product_id']);
        $this->assertSame('attached', $first[0]['status']);
        $this->assertGreaterThanOrEqual(0.85, $first[0]['score']);
        $this->assertSame($first[0]['product_id'], $again[0]['product_id']);
        $this->assertSame(1, DB::table('reel_product_tags')->where('post_id', $post)->count());
        $this->assertSame(1, DB::table('product_tag_suggestions')->where('post_id', $post)->where('product_id', $match)->count());
    }

    public function test_two_equally_good_listings_are_only_suggested(): void
    {
        $frame = $this->reelImage(33);
        $gtin = ['canonical_key' => 'gtin:4006381333931'];
        $a = $this->indexedProduct($frame, 1_000, $gtin);
        $b = $this->indexedProduct($frame, 1_200, $gtin);
        $post = $this->reelPost();

        $suggestions = app(AutoTagService::class)->match($post, $frame);

        $this->assertEqualsCanonicalizing([$a, $b], array_column($suggestions, 'product_id'));
        $this->assertSame(['suggested', 'suggested'], array_column($suggestions, 'status'));
        $this->assertSame(0, DB::table('reel_product_tags')->where('post_id', $post)->count());
    }

    public function test_frames_that_match_nothing_or_are_not_images_attach_nothing(): void
    {
        $this->indexedProduct($this->reelImage(34));
        $post = $this->reelPost();

        $this->assertSame([], app(AutoTagService::class)->match($post, $this->reelImage(35)));
        foreach ([HostileImages::flatJpeg(), HostileImages::pngBomb(), HostileImages::svg()] as $hostile) {
            try {
                app(AutoTagService::class)->match($post, $hostile);
                $this->fail('A hostile frame was analysed.');
            } catch (ApiException $refused) {
                $this->assertSame('IMAGE_REJECTED', $refused->errorCode);
            }
        }
        $this->assertSame(0, DB::table('reel_product_tags')->count());
    }

    public function test_a_copied_photo_for_a_different_product_is_flagged_and_hidden_everywhere(): void
    {
        $photo = $this->reelImage(41);
        $original = $this->indexedProduct($photo, 5_000);
        $copier = $this->verifiedSeller();
        $copy = $this->catalogProduct(4_000, ['seller_id' => $copier->id, 'title' => 'Totally different item']);

        $outcome = app(CatalogImageIndexer::class)->indexBytes($copy, HostileImages::variant($photo, 700, 60, 10));

        $this->assertSame('duplicate', $outcome);
        $this->assertSame('review', DB::table('products')->where('id', $copy)->value('listing_status'));
        $this->assertSame('active', DB::table('products')->where('id', $original)->value('listing_status'));
        $flag = DB::table('listing_flags')->where('product_id', $copy)->first();
        $this->assertSame('duplicate_image', $flag->kind);
        $this->assertSame($original, $flag->related_product_id);
        $this->assertFalse((bool) DB::table('product_embeddings')->where('product_id', $copy)->value('searchable'));

        // Hidden from visual search, from auto-tagging, and not purchasable.
        $ids = array_column(app(VectorStore::class)->nearest(VisualEmbedding::fromBytes($photo), 10), 'product_id');
        $this->assertSame([$original], $ids);
        $buyer = $this->makeUser();
        $this->savedCard($buyer);
        Sanctum::actingAs($buyer);
        $this->postJson('/api/v1/checkout/one-tap', ['product_id' => $copy], $this->key('spoof'))->assertStatus(409)->assertJsonPath('code', 'OUT_OF_STOCK');
    }

    public function test_offers_of_the_same_gtin_may_share_the_makers_photo(): void
    {
        $photo = $this->reelImage(42);
        $gtin = ['canonical_key' => 'gtin:036000291452'];
        $first = $this->indexedProduct($photo, 5_000, $gtin);
        $second = $this->indexedProduct($photo, 4_800, $gtin);

        $this->assertSame(['active', 'active'], DB::table('products')->whereIn('id', [$first, $second])->orderBy('id')->pluck('listing_status')->all());
        $this->assertSame(0, DB::table('listing_flags')->count());
    }

    public function test_a_seller_losing_verification_disappears_from_search(): void
    {
        $photo = $this->reelImage(43);
        $product = $this->indexedProduct($photo);
        $seller = (string) DB::table('products')->where('id', $product)->value('seller_id');

        DB::table('sellers')->where('user_id', $seller)->update(['status' => 'rejected']);

        $this->assertFalse((bool) DB::table('product_embeddings')->where('product_id', $product)->value('searchable'));
        $this->assertSame([], app(VectorStore::class)->nearest(VisualEmbedding::fromBytes($photo), 10));
        DB::table('sellers')->where('user_id', $seller)->update(['status' => 'verified']);
        $this->assertCount(1, app(VectorStore::class)->nearest(VisualEmbedding::fromBytes($photo), 10));
    }

    public function test_catalog_sync_validates_urls_gtins_and_currency_and_indexes_off_the_request(): void
    {
        $seller = $this->verifiedSeller();
        Sanctum::actingAs($seller);
        /** @var FakeImageFetcher $fetcher */
        $fetcher = app(ImageFetcher::class);
        $photo = $this->reelImage(44);
        $fetcher->respond('https://cdn.example.com/jacket.jpg', $photo);
        $fetcher->respond('https://cdn.example.com/bomb.png', HostileImages::pngBomb());
        $item = fn (array $override): array => $override + ['sku' => 'SKU-1', 'title' => 'Jacket', 'brand' => 'Acme', 'price_minor' => 5_000, 'stock' => 3, 'image_url' => 'https://cdn.example.com/jacket.jpg'];

        foreach ([
            ['image_url' => 'http://cdn.example.com/jacket.jpg'],
            ['image_url' => 'https://user@cdn.example.com/x.jpg'],
            ['image_url' => 'javascript:alert(1)'],
            ['image_url' => 'file:///etc/passwd'],
            ['gtin' => '12345'],
            ['price_minor' => 0],
            ['currency' => 'usd'],
        ] as $bad) {
            $this->postJson('/api/v1/seller/catalog', ['products' => [$item($bad)]])->assertStatus(422);
        }
        $this->postJson('/api/v1/seller/catalog', ['products' => [$item(['gtin' => '4006381333932'])]])->assertStatus(422)->assertJsonPath('code', 'GTIN_INVALID');

        $this->postJson('/api/v1/seller/catalog', ['products' => [
            $item(['gtin' => '4006381333931']),
            $item(['sku' => 'SKU-2', 'image_url' => 'https://cdn.example.com/bomb.png']),
            $item(['sku' => 'SKU-3', 'image_url' => 'https://internal.example.com/x.jpg']),
        ]])->assertOk()->assertJsonPath('data.upserted', 3)->assertJsonPath('data.indexing', 3);

        $jacket = DB::table('products')->where('seller_id', $seller->id)->where('sku', 'SKU-1')->first();
        $this->assertSame('gtin:4006381333931', $jacket->canonical_key);
        $this->assertSame(hash('sha256', $photo), $jacket->image_sha256);
        $this->assertTrue((bool) DB::table('product_embeddings')->where('product_id', $jacket->id)->value('searchable'));
        $bomb = DB::table('products')->where('seller_id', $seller->id)->where('sku', 'SKU-2')->first();
        $this->assertSame('review', $bomb->listing_status);
        $this->assertSame('image_rejected', DB::table('listing_flags')->where('product_id', $bomb->id)->value('kind'));
        $unreachable = DB::table('products')->where('seller_id', $seller->id)->where('sku', 'SKU-3')->first();
        $this->assertSame('image_unreachable', DB::table('listing_flags')->where('product_id', $unreachable->id)->value('kind'));

        $this->postJson('/api/v1/seller/catalog', ['products' => [$item(['currency' => 'EUR'])]])->assertStatus(422)->assertJsonPath('code', 'CURRENCY_LOCKED');
        $this->assertSame('USD', DB::table('products')->where('id', $jacket->id)->value('currency'));
    }

    public function test_unverified_sellers_cannot_list_and_their_rows_are_never_searchable(): void
    {
        $pending = $this->makeUser();
        DB::table('sellers')->insert(['user_id' => $pending->id, 'legal_name' => 'Pending Co', 'country' => 'US', 'registration_number' => 'REG-1', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        Sanctum::actingAs($pending);
        $this->postJson('/api/v1/seller/catalog', ['products' => [['sku' => 'A1', 'title' => 'X', 'brand' => 'Y', 'price_minor' => 100, 'stock' => 1, 'image_url' => 'https://cdn.example.com/a.jpg']]])
            ->assertStatus(409);

        $photo = $this->reelImage(45);
        $product = $this->catalogProduct(1_000, ['seller_id' => $pending->id]);
        app(CatalogImageIndexer::class)->indexBytes($product, $photo);
        $this->assertFalse((bool) DB::table('product_embeddings')->where('product_id', $product)->value('searchable'));
    }

    private function reelPost(): string
    {
        $id = (string) Str::uuid7();
        DB::table('feed_posts')->insert([
            'id' => $id,
            'user_id' => $this->makeUser()->id,
            'kind' => 'media',
            'audience' => 'public',
            'media_type' => 'image',
            'media_path' => 'posts/'.$id.'.jpg',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}

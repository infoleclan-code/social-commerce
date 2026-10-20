<?php

declare(strict_types=1);

namespace Tests\Feature\Cache;

use App\Http\Middleware\EnforceIdempotency;
use App\Services\Commerce\ProductGraphService;
use App\Services\Feed\ForYouCandidates;
use App\Support\SingleFlightCache;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\Support\BuildsCommerce;
use Tests\Support\BuildsSocial;
use Tests\TestCase;
use Throwable;

/**
 * Redis-backed caches: For You candidates, multi-seller offers, the product graph and scan results.
 * The rule they all follow is checked here: a cache may be stale, never unsafe (visibility and prices
 * that matter are always re-read from PostgreSQL).
 */
final class CachingTest extends TestCase
{
    use BuildsCommerce;
    use BuildsSocial;
    use RefreshDatabase;

    public function test_the_for_you_head_comes_from_the_cache_and_pages_without_duplicates(): void
    {
        $viewer = $this->makeUser();
        $author = $this->makeUser();
        $ids = [];
        for ($i = 0; $i < 70; $i++) {
            $ids[] = $this->publicPost($author->id, 1000 - $i, $i % 3 === 0 ? ['streetwear'] : []);
        }
        Sanctum::actingAs($viewer);
        $this->putJson('/api/v1/taste', ['styles' => ['streetwear']])->assertOk();

        $seen = [];
        $cursor = null;
        do {
            $page = $this->getJson('/api/v1/feed?mode=for_you&limit=20'.($cursor ? '&cursor='.$cursor : ''))->assertOk();
            array_push($seen, ...$page->json('data.*.id'));
            $cursor = $page->json('meta.next_cursor') ?? $page->json('next_cursor');
        } while ($cursor !== null && count($seen) < 200);

        $this->assertCount(70, $seen);
        $this->assertSame(count($seen), count(array_unique($seen)), 'No post is served twice.');
        $this->assertSame($ids[0], $seen[0], 'Highest score first (it also matches the taste).');
        $streetwear = array_values(array_filter($ids, fn (string $id, int $i): bool => $i % 3 === 0, ARRAY_FILTER_USE_BOTH));
        $this->assertSame(array_slice($streetwear, 0, 5), array_slice($seen, 0, 5), 'Taste-matching posts lead.');
        $this->assertNotEmpty(Cache::get('feed:fy:global:v1'));
    }

    public function test_a_cached_id_never_leaks_a_deleted_hidden_or_blocked_post(): void
    {
        $viewer = $this->makeUser();
        $author = $this->makeUser();
        $blocked = $this->makeUser();
        $ids = [];
        for ($i = 0; $i < 30; $i++) {
            $ids[] = $this->publicPost($author->id, 500 - $i);
        }
        $blockedPost = $this->publicPost($blocked->id, 1000);
        Sanctum::actingAs($viewer);
        $this->getJson('/api/v1/feed?mode=for_you&limit=20')->assertOk();
        $this->assertContains($blockedPost, array_column(app(ForYouCandidates::class)->globalPool(), 'id'));

        DB::table('feed_posts')->where('id', $ids[0])->update(['deleted_at' => now()]);
        DB::table('feed_posts')->where('id', $ids[1])->update(['audience' => 'friends']);
        $this->block($viewer, $blocked);

        $served = $this->getJson('/api/v1/feed?mode=for_you&limit=20')->assertOk()->json('data.*.id');
        $this->assertNotContains($ids[0], $served);
        $this->assertNotContains($ids[1], $served);
        $this->assertNotContains($blockedPost, $served);
        $this->assertContains($ids[0], array_column(app(ForYouCandidates::class)->globalPool(), 'id'), 'The pool was not flushed: hydration filtered it.');
    }

    public function test_offers_are_cached_per_country_and_a_seller_price_change_is_seen_at_once(): void
    {
        $gtin = ['canonical_key' => 'gtin:4006381333931'];
        $cheap = $this->catalogProduct(9_000, $gtin + ['seller_id' => $this->verifiedSeller()->id, 'ships_from' => 'DE', 'vat_bps' => 1900]);
        $seller = $this->verifiedSeller();
        $other = $this->catalogProduct(8_500, $gtin + ['seller_id' => $seller->id, 'sku' => 'SKU-9', 'ships_from' => 'US', 'shipping_minor' => 900, 'vat_bps' => 1900, 'duty_bps' => 500]);
        $hidden = $this->catalogProduct(100, $gtin + ['listing_status' => 'review']);
        $graph = app(ProductGraphService::class);

        $de = $graph->offers($cheap, 'DE');
        $this->assertSame([$cheap, $other], array_column($de['offers'], 'product_id'));
        $this->assertNotContains($hidden, array_column($de['offers'], 'product_id'));

        DB::table('products')->where('id', $other)->update(['price_minor' => 1_000, 'squad_price_minor' => 700]);
        $this->assertSame($de, $graph->offers($cheap, 'DE'), 'Within the TTL a raw DB write is not seen ...');
        Sanctum::actingAs($seller);
        $this->postJson('/api/v1/seller/catalog', ['products' => [[
            'sku' => 'SKU-9', 'title' => 'Same', 'brand' => 'Acme', 'price_minor' => 1_000, 'stock' => 5,
            'image_url' => 'https://example.com/p.jpg', 'gtin' => '4006381333931', 'ships_from' => 'US',
        ]]])->assertOk();
        $this->assertSame($other, $graph->offers($cheap, 'DE')['offers'][0]['product_id'], '... but a seller sync bumps the version.');
        $this->assertSame('US', $graph->offers($cheap, 'us')['destination']);
        $this->getJson('/api/v1/products/'.$cheap.'/offers?country=DE')->assertOk()->assertJsonPath('data.offers.0.product_id', $other);
        $this->getJson('/api/v1/products/not-a-uuid/offers')->assertStatus(404);
    }

    public function test_checkout_ignores_a_stale_cached_price(): void
    {
        $buyer = $this->makeUser();
        $this->savedCard($buyer);
        $product = $this->catalogProduct(5_000, ['canonical_key' => 'gtin:036000291452']);
        $shown = app(ProductGraphService::class)->offers($product, 'US')['offers'][0]['price']['amount_minor'];
        DB::table('products')->where('id', $product)->update(['price_minor' => 6_000, 'squad_price_minor' => 4_200]);
        $this->assertSame($shown, app(ProductGraphService::class)->offers($product, 'US')['offers'][0]['price']['amount_minor']);

        Sanctum::actingAs($buyer);
        $this->postJson('/api/v1/checkout', ['product_id' => $product, 'expected_total_minor' => $shown], $this->key('stale'))
            ->assertStatus(409)->assertJsonPath('code', 'PRICE_CHANGED')->assertJsonPath('meta.total.amount_minor', 6_000);
        $this->postJson('/api/v1/checkout', ['product_id' => $product], $this->key('fresh'))
            ->assertCreated()->assertJsonPath('data.total.amount_minor', 6_000);
    }

    public function test_the_product_graph_links_same_product_co_tagged_and_co_bought(): void
    {
        $gtin = ['canonical_key' => 'gtin:4006381333931'];
        $product = $this->catalogProduct(1_000, $gtin);
        $twin = $this->catalogProduct(1_100, $gtin);
        $tagged = $this->catalogProduct(2_000);
        $bought = $this->catalogProduct(3_000);
        $post = $this->publicPost($this->makeUser()->id, 1);
        DB::table('reel_product_tags')->insert([['post_id' => $post, 'product_id' => $product, 'created_at' => now()], ['post_id' => $post, 'product_id' => $tagged, 'created_at' => now()]]);
        $buyer = $this->makeUser();
        $this->capturedOrder($buyer, $product, 1_000);
        $this->capturedOrder($buyer, $bought, 3_000);

        $edges = collect(app(ProductGraphService::class)->related($product)['edges'])->mapWithKeys(fn (array $edge): array => [$edge['product_id'] => $edge['kind']]);

        $this->assertSame('same_product', $edges[$twin]);
        $this->assertSame('also_tagged', $edges[$tagged]);
        $this->assertSame('also_bought', $edges[$bought]);
    }

    public function test_the_same_scanned_frame_is_searched_once_per_minute(): void
    {
        $image = $this->reelImage(51);
        $this->indexedProduct($image);
        Sanctum::actingAs($this->makeUser());

        DB::enableQueryLog();
        $this->post('/api/v1/visual-search', ['image_base64' => base64_encode($image)], ['Accept' => 'application/json'])->assertOk();
        $first = $this->vectorQueries();
        DB::flushQueryLog();
        $this->post('/api/v1/visual-search', ['image_base64' => base64_encode($image)], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonCount(1, 'data.results');

        $this->assertGreaterThan(0, $first);
        $this->assertSame(0, $this->vectorQueries());
        $this->assertSame(2, DB::table('visual_scans')->count(), 'Every scan is still logged.');
    }

    public function test_single_flight_serves_stale_while_one_caller_refreshes_and_survives_an_outage(): void
    {
        $cache = new SingleFlightCache(new Repository(new ArrayStore));
        $calls = 0;
        $compute = function () use (&$calls): int {
            return ++$calls;
        };
        $this->assertSame(1, $cache->remember('k', 1, 60, $compute));
        $this->assertSame(1, $cache->remember('k', 1, 60, $compute));
        $this->travel(2)->seconds();
        $this->assertSame(2, $cache->remember('k', 1, 60, $compute), 'Stale: the caller holding the lock refreshes.');
        $this->assertSame(2, $calls);

        $cache->bump('ns');
        $this->assertSame(2, $cache->version('ns'));

        $broken = new SingleFlightCache(new class(new ArrayStore) extends Repository
        {
            public function get($key, $default = null): mixed
            {
                throw new RuntimeException('redis down');
            }
        });
        $this->assertSame(3, $broken->remember('k', 10, 10, $compute), 'An outage degrades to "no cache", not to an error.');
        $this->assertSame(0, $broken->version('ns'));
    }

    public function test_the_redis_store_works_with_locks_and_versions(): void
    {
        try {
            $store = Cache::store('redis');
            $store->put('probe', 1, 5);
        } catch (Throwable) {
            $this->markTestSkipped('Redis is not reachable in this environment.');
        }
        $key = 'test:sf:'.Str::random(8);
        $cache = new SingleFlightCache($store);
        $calls = 0;
        $value = $cache->remember($key, 30, 30, function () use (&$calls): array {
            $calls++;

            return ['ids' => [1, 2, 3]];
        });
        $this->assertSame(['ids' => [1, 2, 3]], $value);
        $this->assertSame($value, $cache->remember($key, 30, 30, fn () => $this->fail('Recomputed a fresh key.')));
        $ns = 'test-ns-'.Str::random(6);
        $before = $cache->version($ns);
        $cache->bump($ns);
        $this->assertSame($before + 1, $cache->version($ns));
        $this->assertTrue($store->lock($key.':refresh', 5)->get());
        $store->forget($key);
    }

    public function test_single_flight_lock_contention_and_lock_failures_never_block_or_break_reads(): void
    {
        $store = new Repository(new ArrayStore);
        $cache = new SingleFlightCache($store);
        $calls = 0;
        $compute = function () use (&$calls): int {
            return ++$calls;
        };
        $cache->remember('hot', 1, 60, $compute);
        $this->travel(2)->seconds();
        $other = $store->lock('hot:refresh', 10);
        $this->assertTrue($other->get(), 'Another worker is refreshing.');
        $this->assertSame(1, $cache->remember('hot', 1, 60, $compute), 'Stale value served while someone else refreshes.');
        $other->release();

        $timeouts = new SingleFlightCache(new class(new ArrayStore) extends Repository
        {
            public function lock($name, $seconds = 0, $owner = null)
            {
                return new class
                {
                    public function block(int $seconds): bool
                    {
                        throw new LockTimeoutException;
                    }

                    public function get(): bool
                    {
                        throw new RuntimeException('lock backend down');
                    }

                    public function release(): bool
                    {
                        return true;
                    }
                };
            }
        });
        $this->assertSame(2, $timeouts->remember('cold', 10, 10, $compute), 'A lock timeout computes without the cache.');

        $staleStore = new class(new ArrayStore) extends Repository
        {
            public function lock($name, $seconds = 0, $owner = null)
            {
                throw new RuntimeException('lock backend down');
            }
        };
        $staleStore->put('warm', ['at' => (float) now()->subSeconds(5)->format('U.u'), 'value' => 'stale'], 60);
        $this->assertSame('stale', (new SingleFlightCache($staleStore))->remember('warm', 1, 60, $compute));
        $this->assertSame(3, (new SingleFlightCache($staleStore))->remember('missing', 1, 60, $compute));
        (new SingleFlightCache($staleStore))->forget('warm');
    }

    public function test_the_idempotency_layer_is_transparent_for_anonymous_requests(): void
    {
        $middleware = new EnforceIdempotency;
        $response = $middleware->handle(Request::create('/x', 'POST'), fn () => response()->json(['ok' => true]));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, DB::table('idempotency_keys')->count());
    }

    private function vectorQueries(): int
    {
        return count(array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'product_embeddings')));
    }

    /** @param  list<string>  $tags */
    private function publicPost(string $authorId, float $rank, array $tags = []): string
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
        DB::table('feed_posts')->where('id', $id)->update(['rank_score' => $rank]);

        return $id;
    }
}

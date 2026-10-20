<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Services\Media\VisualEmbedding;
use App\Services\Media\VisualSignature;
use App\Services\Search\VectorStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsCommerce;
use Tests\Support\BuildsSocial;
use Tests\TestCase;

/** The pgvector schema and query shape: the HNSW indexes exist, are valid, and serve our queries. */
final class VectorIndexTest extends TestCase
{
    use BuildsCommerce;
    use BuildsSocial;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (! app(VectorStore::class)->usesPgvector()) {
            $this->markTestSkipped('pgvector is not installed in this database.');
        }
    }

    public function test_the_hnsw_indexes_exist_with_the_production_parameters(): void
    {
        $definitions = DB::table('pg_indexes')->where('tablename', 'product_embeddings')->pluck('indexdef', 'indexname');

        $this->assertStringContainsString('USING hnsw (luma vector_cosine_ops) WITH (m=\'16\', ef_construction=\'128\') WHERE searchable', $definitions['product_embeddings_luma_hnsw']);
        $this->assertStringContainsString('USING hnsw (phash bit_hamming_ops)', $definitions['product_embeddings_phash_hnsw']);
        $this->assertSame('0.8.7', DB::table('pg_extension')->where('extname', 'vector')->value('extversion'));
    }

    public function test_the_search_query_is_served_by_the_partial_hnsw_index(): void
    {
        for ($seed = 60; $seed < 66; $seed++) {
            $this->indexedProduct($this->reelImage($seed));
        }
        $query = VisualSignature::vectorLiteral(VisualEmbedding::fromBytes($this->reelImage(60))->luma);

        $plan = DB::transaction(function () use ($query): string {
            DB::statement('SET LOCAL enable_seqscan = off');
            $rows = DB::select('EXPLAIN SELECT e.product_id FROM product_embeddings e WHERE e.searchable ORDER BY e.luma <=> ?::vector LIMIT 64', [$query]);

            return implode("\n", array_map(fn (object $row): string => (string) $row->{'QUERY PLAN'}, $rows));
        });
        $this->assertStringContainsString('Index Scan using product_embeddings_luma_hnsw', $plan);

        $hamming = DB::transaction(function (): string {
            DB::statement('SET LOCAL enable_seqscan = off');
            $rows = DB::select('EXPLAIN SELECT product_id FROM product_embeddings ORDER BY phash <~> ?::bit(64) LIMIT 20', [str_repeat('01', 32)]);

            return implode("\n", array_map(fn (object $row): string => (string) $row->{'QUERY PLAN'}, $rows));
        });
        $this->assertStringContainsString('product_embeddings_phash_hnsw', $hamming);
    }

    public function test_nearest_returns_exact_rescored_matches_and_honours_stock(): void
    {
        $photo = $this->reelImage(70);
        $match = $this->indexedProduct($photo);
        $soldOut = $this->indexedProduct($this->reelImage(71));
        DB::table('products')->where('id', $soldOut)->update(['stock' => 0]);

        $rows = app(VectorStore::class)->nearest(VisualEmbedding::fromBytes($photo), 10);

        $this->assertSame($match, $rows[0]->product_id);
        $this->assertEqualsWithDelta(1.0, $rows[0]->luma_score, 1e-5);
        $this->assertEqualsWithDelta(1.0, $rows[0]->structure_score, 1e-5);
        $this->assertNotContains($soldOut, array_column($rows, 'product_id'));
        $this->assertSame('100', DB::transaction(function (): string {
            DB::selectOne("SELECT set_config('hnsw.ef_search', '100', true)");

            return (string) DB::selectOne('SHOW hnsw.ef_search')->{'hnsw.ef_search'};
        }));
    }

    public function test_the_database_keeps_searchable_in_sync_with_listing_state(): void
    {
        $product = $this->indexedProduct($this->reelImage(72));
        $searchable = fn (): bool => (bool) DB::table('product_embeddings')->where('product_id', $product)->value('searchable');

        $this->assertTrue($searchable());
        DB::table('products')->where('id', $product)->update(['listing_status' => 'blocked']);
        $this->assertFalse($searchable());
        DB::table('products')->where('id', $product)->update(['listing_status' => 'active']);
        $this->assertTrue($searchable());
        DB::table('products')->where('id', $product)->update(['seller_id' => $this->makeUser()->id]);
        $this->assertFalse($searchable(), 'Moved to a seller without verification.');
        DB::table('product_embeddings')->where('product_id', $product)->update(['searchable' => true]);
        $this->assertFalse($searchable(), 'The flag cannot be forced: the trigger recomputes it.');
    }
}

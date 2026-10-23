<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Visual search at catalog scale.
 *
 * product_embeddings holds one visual_v2 signature per product photo:
 *  - luma, structure: vector(64) (pgvector), compared by cosine distance;
 *  - phash: bit(64) difference hash, compared by Hamming distance (duplicate listing detection);
 *  - searchable: maintained by triggers from the listing status and the seller's verification, so the
 *    HNSW index only contains listings a buyer may see, and the index predicate matches the query.
 *
 * Indexes (pgvector 0.8):
 *  - HNSW (luma vector_cosine_ops) WITH (m = 16, ef_construction = 128) WHERE searchable. HNSW keeps
 *    recall high without the training/rebuild cycle IVFFlat needs as the catalog grows, and supports
 *    iterative scans (hnsw.iterative_scan) so filters after the ANN step do not starve results.
 *    Query-time knobs (hnsw.ef_search, iterative_scan, max_scan_tuples) are set per transaction by
 *    App\Services\Search\VectorStore.
 *  - HNSW (phash bit_hamming_ops) over all listings, for near-duplicate photos across sellers.
 * The table is new and empty, so the indexes are created here and maintained incrementally. For a
 * multi-million backfill, load first and build afterwards: php artisan visual-search:rebuild-index
 * (REINDEX CONCURRENTLY with parallel workers, no write lock).
 *
 * Without the vector extension (some local setups) the same columns are double precision[] and
 * search falls back to an exact scan; production must have pgvector >= 0.8 (visual-search:doctor).
 */
return new class extends Migration
{
    public function up(): void
    {
        $vector = $this->enableVector();
        $type = $vector ? 'vector(64)' : 'double precision[]';
        $shape = $vector ? '' : ' CHECK (cardinality(%s) = 64)';

        DB::unprepared(sprintf(<<<'SQL'
            CREATE TABLE product_embeddings (
              product_id    uuid PRIMARY KEY REFERENCES products (id) ON DELETE CASCADE,
              seller_id     uuid,
              model         text NOT NULL CHECK (model IN ('visual_v2')),
              luma          %1$s NOT NULL%2$s,
              structure     %1$s NOT NULL%3$s,
              phash         bit(64) NOT NULL,
              image_sha256  char(64) NOT NULL CHECK (image_sha256 ~ '^[0-9a-f]{64}$'),
              searchable    boolean NOT NULL DEFAULT false,
              updated_at    timestamptz NOT NULL DEFAULT now()
            );
            CREATE INDEX product_embeddings_sha_idx ON product_embeddings (image_sha256);
            CREATE INDEX product_embeddings_seller_idx ON product_embeddings (seller_id) WHERE seller_id IS NOT NULL;

            CREATE OR REPLACE FUNCTION product_embeddings_searchable() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              SELECT p.seller_id,
                     p.listing_status = 'active'
                     AND (p.seller_id IS NULL OR EXISTS (
                           SELECT 1 FROM sellers s WHERE s.user_id = p.seller_id AND s.status = 'verified'))
                INTO NEW.seller_id, NEW.searchable
                FROM products p
               WHERE p.id = NEW.product_id;
              NEW.searchable := COALESCE(NEW.searchable, false);
              RETURN NEW;
            END $$;
            CREATE TRIGGER product_embeddings_searchable
              BEFORE INSERT OR UPDATE ON product_embeddings
              FOR EACH ROW EXECUTE FUNCTION product_embeddings_searchable();

            CREATE OR REPLACE FUNCTION products_refresh_searchable() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              IF NEW.listing_status IS DISTINCT FROM OLD.listing_status OR NEW.seller_id IS DISTINCT FROM OLD.seller_id THEN
                UPDATE product_embeddings SET updated_at = now() WHERE product_id = NEW.id;
              END IF;
              RETURN NULL;
            END $$;
            CREATE TRIGGER products_refresh_searchable
              AFTER UPDATE OF listing_status, seller_id ON products
              FOR EACH ROW EXECUTE FUNCTION products_refresh_searchable();

            CREATE OR REPLACE FUNCTION sellers_refresh_searchable() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              IF NEW.status IS DISTINCT FROM OLD.status THEN
                UPDATE product_embeddings SET updated_at = now() WHERE seller_id = NEW.user_id;
              END IF;
              RETURN NULL;
            END $$;
            CREATE TRIGGER sellers_refresh_searchable
              AFTER UPDATE OF status ON sellers
              FOR EACH ROW EXECUTE FUNCTION sellers_refresh_searchable();

            SQL, $type, sprintf($shape, 'luma'), sprintf($shape, 'structure')));

        if ($vector) {
            DB::unprepared(<<<'SQL'
                CREATE INDEX product_embeddings_luma_hnsw ON product_embeddings
                  USING hnsw (luma vector_cosine_ops) WITH (m = 16, ef_construction = 128)
                  WHERE searchable;
                CREATE INDEX product_embeddings_phash_hnsw ON product_embeddings
                  USING hnsw (phash bit_hamming_ops) WITH (m = 16, ef_construction = 64);
                SQL);
        } else {
            Log::warning('pgvector is not available: visual search will use an exact scan. Install pgvector >= 0.8 for production.');
        }
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS sellers_refresh_searchable ON sellers;
            DROP FUNCTION IF EXISTS sellers_refresh_searchable();
            DROP TRIGGER IF EXISTS products_refresh_searchable ON products;
            DROP FUNCTION IF EXISTS products_refresh_searchable();
            DROP TABLE IF EXISTS product_embeddings;
            DROP FUNCTION IF EXISTS product_embeddings_searchable();
            SQL);
    }

    /** Creates the extension when the server has it; a missing or forbidden extension is not fatal. */
    private function enableVector(): bool
    {
        if (DB::selectOne("SELECT 1 AS ok FROM pg_available_extensions WHERE name = 'vector'") === null) {
            return false;
        }
        try {
            DB::transaction(fn () => DB::statement('CREATE EXTENSION IF NOT EXISTS vector'));
        } catch (Throwable $denied) {
            Log::warning('pgvector is installed but could not be enabled: '.$denied->getMessage());

            return false;
        }

        $installed = DB::selectOne("SELECT extversion FROM pg_extension WHERE extname = 'vector'");
        if ($installed === null) {
            return false;
        }
        // 0.6 (Ubuntu 24.04) has neither bit_hamming_ops nor the 0.8 HNSW knobs. Treat it as absent
        // so columns stay double precision[] and search uses an exact scan. Production wants >= 0.8.
        if (version_compare((string) $installed->extversion, '0.8.0', '<')) {
            Log::warning('pgvector '.(string) $installed->extversion.' is older than 0.8: visual search will use an exact scan.');

            return false;
        }

        return true;
    }
};

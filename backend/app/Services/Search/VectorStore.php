<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Services\Media\VisualEmbedding;
use App\Services\Media\VisualSignature;
use Illuminate\Support\Facades\DB;

/**
 * product_embeddings access, on pgvector when available (HNSW) and an exact scan otherwise.
 *
 * Nearest-neighbour queries run in their own transaction so the HNSW knobs are SET LOCAL:
 *  - hnsw.ef_search (default 100): candidate list size, the recall/latency dial;
 *  - hnsw.iterative_scan = relaxed_order: if filters after the index scan (stock, listing state)
 *    remove candidates, pgvector keeps scanning instead of returning too few rows;
 *  - hnsw.max_scan_tuples: hard bound on that extra work, so a pathological query cannot spike latency.
 * The inner query is a plain ORDER BY distance LIMIT k over the partial index (WHERE searchable),
 * which is the shape the planner serves from HNSW; scores are re-ranked exactly afterwards.
 */
final class VectorStore
{
    private static ?bool $pgvector = null;

    public function usesPgvector(): bool
    {
        if (self::$pgvector === null) {
            $type = DB::selectOne(
                "SELECT format_type(a.atttypid, a.atttypmod) AS type
                   FROM pg_attribute a
                  WHERE a.attrelid = 'product_embeddings'::regclass AND a.attname = 'luma' AND NOT a.attisdropped",
            );
            self::$pgvector = $type !== null && str_starts_with((string) $type->type, 'vector');
        }

        return self::$pgvector;
    }

    public function upsert(string $productId, VisualSignature $signature): void
    {
        $pg = $this->usesPgvector();
        DB::statement(
            sprintf(
                'INSERT INTO product_embeddings (product_id, model, luma, structure, phash, image_sha256, updated_at)
                 VALUES (?, ?, %1$s, %1$s, ?::bit(64), ?, now())
                 ON CONFLICT (product_id) DO UPDATE SET
                   model = EXCLUDED.model, luma = EXCLUDED.luma, structure = EXCLUDED.structure,
                   phash = EXCLUDED.phash, image_sha256 = EXCLUDED.image_sha256, updated_at = now()',
                $pg ? '?::vector' : '?::float8[]',
            ),
            [
                $productId,
                VisualEmbedding::MODEL,
                $pg ? VisualSignature::vectorLiteral($signature->luma) : VisualSignature::arrayLiteral($signature->luma),
                $pg ? VisualSignature::vectorLiteral($signature->structure) : VisualSignature::arrayLiteral($signature->structure),
                $signature->dhash,
                $signature->sha256,
            ],
        );
    }

    /**
     * The closest searchable, in-stock listings by luma, with both scores.
     *
     * @return list<object{product_id: string, luma_score: float, structure_score: float}>
     */
    public function nearest(VisualSignature $query, int $limit): array
    {
        $limit = max(1, min(200, $limit));
        if (! $this->usesPgvector()) {
            return $this->exact($query, $limit);
        }
        $luma = VisualSignature::vectorLiteral($query->luma);
        $structure = VisualSignature::vectorLiteral($query->structure);

        return DB::transaction(function () use ($luma, $structure, $limit): array {
            DB::selectOne("SELECT set_config('hnsw.ef_search', ?, true)", [(string) max($limit, (int) config('commerce.visual_search.ef_search', 100))]);
            DB::selectOne("SELECT set_config('hnsw.iterative_scan', 'relaxed_order', true)");
            DB::selectOne("SELECT set_config('hnsw.max_scan_tuples', ?, true)", [(string) (int) config('commerce.visual_search.max_scan_tuples', 20000)]);

            return array_map(fn (object $row): object => (object) [
                'product_id' => (string) $row->product_id,
                'luma_score' => (float) $row->luma_score,
                'structure_score' => (float) $row->structure_score,
            ], DB::select(
                "SELECT c.product_id, c.luma_score, 1 - (e.structure <=> ?::vector) AS structure_score
                   FROM (
                         SELECT e.product_id, 1 - (e.luma <=> ?::vector) AS luma_score
                           FROM product_embeddings e
                          WHERE e.searchable
                          ORDER BY e.luma <=> ?::vector
                          LIMIT ?
                        ) c
                   JOIN product_embeddings e ON e.product_id = c.product_id
                   JOIN products p ON p.id = c.product_id
                  WHERE p.stock > 0 AND p.listing_status = 'active'
                  ORDER BY c.luma_score DESC, c.product_id",
                [$structure, $luma, $luma, $limit],
            ));
        });
    }

    /**
     * Listings from OTHER sellers whose photo is the same file, or a near-duplicate: Hamming distance
     * of the dHash within $maxDistance AND luma correlation of at least $minLuma (two independent
     * signals, so a coincidental hash collision does not flag an honest listing).
     *
     * @return list<object{product_id: string, seller_id: string|null, distance: int, luma_score: float}>
     */
    public function duplicates(string $productId, VisualSignature $signature, ?string $sellerId, int $maxDistance, float $minLuma = 0.95): array
    {
        $pg = $this->usesPgvector();
        $distance = $pg ? 'e.phash <~> ?::bit(64)' : 'bit_count(e.phash # ?::bit(64))';
        $luma = $pg ? '1 - (e.luma <=> ?::vector)' : 'embedding_cosine(e.luma, ?::float8[])';
        $literal = $pg ? VisualSignature::vectorLiteral($signature->luma) : VisualSignature::arrayLiteral($signature->luma);
        $rows = DB::select(
            "SELECT c.product_id, c.seller_id, c.image_sha256, c.distance, {$luma} AS luma_score
               FROM (
                     SELECT e.product_id, e.seller_id, e.image_sha256, ({$distance})::int AS distance
                       FROM product_embeddings e
                      WHERE e.product_id <> ?
                      ORDER BY {$distance}
                      LIMIT 20
                    ) c
               JOIN product_embeddings e ON e.product_id = c.product_id",
            [$literal, $signature->dhash, $productId, $signature->dhash],
        );
        $found = [];
        foreach ($rows as $row) {
            $sameFile = hash_equals((string) $row->image_sha256, $signature->sha256);
            if ($sameFile || ((int) $row->distance <= $maxDistance && (float) $row->luma_score >= $minLuma)) {
                $found[] = (object) [
                    'product_id' => (string) $row->product_id,
                    'seller_id' => $row->seller_id === null ? null : (string) $row->seller_id,
                    'distance' => $sameFile ? 0 : (int) $row->distance,
                    'luma_score' => (float) $row->luma_score,
                ];
            }
        }
        foreach (DB::table('product_embeddings')->where('image_sha256', $signature->sha256)->where('product_id', '<>', $productId)->limit(20)->get(['product_id', 'seller_id']) as $row) {
            if (! in_array((string) $row->product_id, array_column($found, 'product_id'), true)) {
                $found[] = (object) ['product_id' => (string) $row->product_id, 'seller_id' => $row->seller_id === null ? null : (string) $row->seller_id, 'distance' => 0, 'luma_score' => 1.0];
            }
        }

        return array_values(array_filter($found, fn (object $row): bool => $sellerId === null || $row->seller_id !== $sellerId));
    }

    /** @return list<object{product_id: string, luma_score: float, structure_score: float}> */
    private function exact(VisualSignature $query, int $limit): array
    {
        $rows = DB::select(
            "SELECT e.product_id,
                    embedding_cosine(e.luma, ?::float8[]) AS luma_score,
                    embedding_cosine(e.structure, ?::float8[]) AS structure_score
               FROM product_embeddings e
               JOIN products p ON p.id = e.product_id
              WHERE e.searchable AND p.stock > 0 AND p.listing_status = 'active'
              ORDER BY luma_score DESC, e.product_id
              LIMIT ?",
            [VisualSignature::arrayLiteral($query->luma), VisualSignature::arrayLiteral($query->structure), $limit],
        );

        return array_map(fn (object $row): object => (object) [
            'product_id' => (string) $row->product_id,
            'luma_score' => (float) $row->luma_score,
            'structure_score' => (float) $row->structure_score,
        ], $rows);
    }

    /** For tests and migrations that switch storage. */
    public static function forget(): void
    {
        self::$pgvector = null;
    }
}

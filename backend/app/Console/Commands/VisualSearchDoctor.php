<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Checks that visual search runs on pgvector with valid HNSW indexes, and reports coverage. */
final class VisualSearchDoctor extends Command
{
    protected $signature = 'visual-search:doctor';

    protected $description = 'Check pgvector, the HNSW indexes and catalogue coverage';

    public function handle(): int
    {
        $ok = true;
        $version = DB::selectOne("SELECT extversion FROM pg_extension WHERE extname = 'vector'");
        if ($version === null) {
            $this->error('pgvector is not installed: visual search uses an exact scan (fine for development only).');
            $ok = false;
        } else {
            $this->info('pgvector '.$version->extversion.(version_compare((string) $version->extversion, '0.8.0', '>=') ? '' : ' (upgrade to >= 0.8 for iterative scans)'));
        }
        foreach (['product_embeddings_luma_hnsw', 'product_embeddings_phash_hnsw'] as $index) {
            $row = DB::selectOne(
                'SELECT i.indisvalid AS valid, pg_size_pretty(pg_relation_size(c.oid)) AS size
                   FROM pg_class c JOIN pg_index i ON i.indexrelid = c.oid WHERE c.relname = ?',
                [$index],
            );
            if ($row === null || ! $row->valid) {
                $this->error("{$index}: ".($row === null ? 'missing' : 'INVALID (run visual-search:rebuild-index)'));
                $ok = false;
            } else {
                $this->info("{$index}: valid, {$row->size}");
            }
        }
        $coverage = DB::selectOne(
            "SELECT count(*) FILTER (WHERE p.listing_status = 'active') AS active,
                    count(e.product_id) FILTER (WHERE e.searchable) AS searchable,
                    count(*) FILTER (WHERE p.listing_status = 'active' AND e.product_id IS NULL) AS missing
               FROM products p LEFT JOIN product_embeddings e ON e.product_id = p.id",
        );
        $this->line("active listings: {$coverage->active}; searchable: {$coverage->searchable}; active without a signature: {$coverage->missing}");
        $flags = (int) DB::table('listing_flags')->where('status', 'open')->count();
        $this->line("open listing flags (moderation queue): {$flags}");

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}

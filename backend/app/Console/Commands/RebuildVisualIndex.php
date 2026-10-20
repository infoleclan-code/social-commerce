<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds the visual-search HNSW indexes without blocking writes (REINDEX CONCURRENTLY), with
 * build memory and parallel workers raised for this session only. Use after a bulk backfill
 * (catalog:index-images), or if a build was interrupted (INVALID index).
 */
final class RebuildVisualIndex extends Command
{
    protected $signature = 'visual-search:rebuild-index {--memory=2GB : maintenance_work_mem for the build} {--workers=2 : max_parallel_maintenance_workers}';

    protected $description = 'Rebuild the pgvector HNSW indexes concurrently';

    public function handle(): int
    {
        if (preg_match('/^\d+(MB|GB)$/', (string) $this->option('memory')) !== 1) {
            $this->error('--memory must look like 512MB or 2GB.');

            return self::FAILURE;
        }
        DB::statement("SET maintenance_work_mem = '".$this->option('memory')."'");
        DB::statement('SET max_parallel_maintenance_workers = '.max(0, min(16, (int) $this->option('workers'))));
        foreach (['product_embeddings_luma_hnsw', 'product_embeddings_phash_hnsw'] as $index) {
            if (DB::selectOne('SELECT 1 AS ok FROM pg_class WHERE relname = ?', [$index]) === null) {
                $this->warn("{$index} does not exist (pgvector missing?).");

                continue;
            }
            $started = microtime(true);
            DB::statement('REINDEX INDEX CONCURRENTLY '.$index);
            $this->info(sprintf('%s rebuilt in %.1f s', $index, microtime(true) - $started));
        }

        return self::SUCCESS;
    }
}

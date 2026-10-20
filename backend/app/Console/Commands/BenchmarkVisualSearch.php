<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reproducible pgvector benchmark for the visual-search index shape, on synthetic visual_v2-like data
 * (zero-mean, unit-length 64-d vectors in clusters, so near neighbours exist as in a real catalogue).
 *
 * It builds the same HNSW index as product_embeddings (m=16, ef_construction=128, cosine, partial on
 * searchable), then measures latency percentiles and recall@10 against an exact scan for several
 * hnsw.ef_search values. Everything lives in a throwaway schema (vs_bench) that is dropped afterwards.
 * Never run it on a production primary: it writes and indexes --rows vectors.
 */
final class BenchmarkVisualSearch extends Command
{
    protected $signature = 'visual-search:benchmark
        {--rows=1000000 : Vectors to generate}
        {--queries=200 : Timed queries per ef_search value}
        {--recall-queries=50 : Queries checked against an exact scan}
        {--ef=40,100,200 : hnsw.ef_search values to compare}
        {--workers=1 : max_parallel_maintenance_workers for the build}
        {--keep : Keep the vs_bench schema afterwards}
        {--force : Allow running in production}';

    protected $description = 'Benchmark the HNSW visual-search index at catalogue scale (latency percentiles and recall)';

    public function handle(): int
    {
        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('Refusing to run in production without --force.');

            return self::FAILURE;
        }
        if (DB::selectOne("SELECT 1 AS ok FROM pg_extension WHERE extname = 'vector'") === null) {
            $this->error('pgvector is not installed in this database.');

            return self::FAILURE;
        }
        $rows = max(1000, (int) $this->option('rows'));
        $clusters = max(10, intdiv($rows, 200));
        DB::unprepared('DROP SCHEMA IF EXISTS vs_bench CASCADE; CREATE SCHEMA vs_bench;');
        try {
            $this->line("Generating {$rows} vectors in {$clusters} clusters ...");
            $started = microtime(true);
            DB::unprepared(sprintf(<<<'SQL'
                CREATE UNLOGGED TABLE vs_bench.centers AS
                  SELECT c AS id, ARRAY(SELECT (random() - 0.5)::float8 FROM generate_series(1, 64) WHERE c >= 0) AS v
                    FROM generate_series(0, %1$d - 1) c;
                CREATE UNLOGGED TABLE vs_bench.embeddings (id bigint PRIMARY KEY, luma vector(64) NOT NULL, searchable boolean NOT NULL);
                INSERT INTO vs_bench.embeddings (id, luma, searchable)
                SELECT i,
                       l2_normalize((
                         SELECT array_agg(val - avgv ORDER BY d)::vector
                           FROM (SELECT d, val, avg(val) OVER () AS avgv
                                   FROM (SELECT d, c.v[d] + (random() - 0.5) * 0.35 AS val
                                           FROM generate_series(1, 64) d) s) t)),
                       (i %% 10) <> 0
                  FROM generate_series(1, %2$d) i
                  JOIN vs_bench.centers c ON c.id = i %% %1$d;
                ANALYZE vs_bench.embeddings;
                SQL, $clusters, $rows));
            $this->line(sprintf('  generated in %.1f s', microtime(true) - $started));

            $started = microtime(true);
            DB::statement("SET maintenance_work_mem = '1GB'");
            DB::statement('SET max_parallel_maintenance_workers = '.max(0, (int) $this->option('workers')));
            DB::statement('CREATE INDEX vs_bench_luma_hnsw ON vs_bench.embeddings USING hnsw (luma vector_cosine_ops) WITH (m = 16, ef_construction = 128) WHERE searchable');
            $build = microtime(true) - $started;
            $size = DB::selectOne("SELECT pg_size_pretty(pg_relation_size('vs_bench.vs_bench_luma_hnsw')) AS index_size, pg_size_pretty(pg_relation_size('vs_bench.embeddings')) AS table_size");
            $this->line(sprintf('  HNSW built in %.1f s; index %s, table %s', $build, $size->index_size, $size->table_size));

            $queries = $this->queries(max(1, (int) $this->option('queries')));
            $recallQueries = array_slice($queries, 0, max(1, (int) $this->option('recall-queries')));
            $exact = $this->exact($recallQueries);
            $plan = $this->plan($queries[0]);
            $this->line('  plan: '.$plan);

            $table = [];
            foreach (array_map('intval', explode(',', (string) $this->option('ef'))) as $ef) {
                [$latencies, $recall] = $this->measure($queries, $recallQueries, $exact, $ef);
                sort($latencies);
                $table[] = [
                    $ef,
                    sprintf('%.2f', $this->percentile($latencies, 50)),
                    sprintf('%.2f', $this->percentile($latencies, 95)),
                    sprintf('%.2f', $this->percentile($latencies, 99)),
                    sprintf('%.3f', $recall),
                ];
            }
            $this->table(['ef_search', 'p50 ms', 'p95 ms', 'p99 ms', 'recall@10'], $table);
            $this->line(sprintf('rows=%d clusters=%d searchable=90%% build_s=%.1f', $rows, $clusters, $build));
        } finally {
            if (! $this->option('keep')) {
                DB::unprepared('DROP SCHEMA IF EXISTS vs_bench CASCADE;');
            }
        }

        return self::SUCCESS;
    }

    /** Query vectors: perturbed copies of stored vectors (a re-photographed product). @return list<string> */
    private function queries(int $count): array
    {
        return array_map(fn (object $row): string => (string) $row->q, DB::select(
            'SELECT l2_normalize((SELECT array_agg(x + (random() - 0.5) * 0.05 ORDER BY n)::vector
                                    FROM unnest(luma::real[]) WITH ORDINALITY AS u(x, n)))::text AS q
               FROM vs_bench.embeddings TABLESAMPLE SYSTEM (1)
              WHERE searchable
              LIMIT ?',
            [$count],
        ));
    }

    /** @param  list<string>  $queries
     * @return list<list<int>>
     */
    private function exact(array $queries): array
    {
        $ids = [];
        DB::transaction(function () use ($queries, &$ids): void {
            DB::statement('SET LOCAL enable_indexscan = off');
            DB::statement('SET LOCAL enable_bitmapscan = off');
            foreach ($queries as $query) {
                $ids[] = array_map('intval', array_column(DB::select(
                    'SELECT id FROM vs_bench.embeddings WHERE searchable ORDER BY luma <=> ?::vector LIMIT 10',
                    [$query],
                ), 'id'));
            }
        });

        return $ids;
    }

    /**
     * @param  list<string>  $queries
     * @param  list<string>  $recallQueries
     * @param  list<list<int>>  $exact
     * @return array{0: list<float>, 1: float}
     */
    private function measure(array $queries, array $recallQueries, array $exact, int $ef): array
    {
        $latencies = [];
        $hits = 0;
        DB::transaction(function () use ($queries, $recallQueries, $exact, $ef, &$latencies, &$hits): void {
            DB::selectOne("SELECT set_config('hnsw.ef_search', ?, true)", [(string) $ef]);
            DB::selectOne("SELECT set_config('hnsw.iterative_scan', 'relaxed_order', true)");
            DB::selectOne("SELECT set_config('hnsw.max_scan_tuples', '20000', true)");
            $sql = 'SELECT id FROM vs_bench.embeddings WHERE searchable ORDER BY luma <=> ?::vector LIMIT 64';
            DB::select($sql, [$queries[0]]);
            foreach ($queries as $query) {
                $start = hrtime(true);
                DB::select($sql, [$query]);
                $latencies[] = (hrtime(true) - $start) / 1_000_000;
            }
            foreach ($recallQueries as $index => $query) {
                $found = array_map('intval', array_column(DB::select(
                    'SELECT id FROM vs_bench.embeddings WHERE searchable ORDER BY luma <=> ?::vector LIMIT 10',
                    [$query],
                ), 'id'));
                $hits += count(array_intersect($found, $exact[$index]));
            }
        });

        return [$latencies, $hits / (10 * count($recallQueries))];
    }

    private function plan(string $query): string
    {
        $lines = DB::select('EXPLAIN SELECT id FROM vs_bench.embeddings WHERE searchable ORDER BY luma <=> ?::vector LIMIT 64', [$query]);

        $plan = trim(implode(' | ', array_map(fn (object $row): string => trim((string) $row->{'QUERY PLAN'}), $lines)));

        return (string) preg_replace("/'\\[[^\\]]*\\]'/", "'[query]'", $plan);
    }

    /** @param  list<float>  $sorted */
    private function percentile(array $sorted, int $p): float
    {
        return $sorted[(int) min(count($sorted) - 1, max(0, ceil($p / 100 * count($sorted)) - 1))];
    }
}

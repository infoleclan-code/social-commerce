<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\IndexProductImage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class IndexCatalogImages extends Command
{
    protected $signature = 'catalog:index-images {--all : Re-index listings that already have a signature} {--limit=10000} {--sync : Run inline instead of queueing}';

    protected $description = 'Queue visual-search indexing (and duplicate checks) for catalog photos';

    public function handle(): int
    {
        $query = DB::table('products as p')
            ->when(! $this->option('all'), fn ($q) => $q->leftJoin('product_embeddings as e', 'e.product_id', '=', 'p.id')->whereNull('e.product_id'))
            ->orderBy('p.id')
            ->limit(max(1, (int) $this->option('limit')));
        $count = 0;
        foreach ($query->pluck('p.id') as $id) {
            $this->option('sync') ? IndexProductImage::dispatchSync((string) $id) : IndexProductImage::dispatch((string) $id);
            $count++;
        }
        $this->info("Queued {$count} listing(s).");

        return self::SUCCESS;
    }
}

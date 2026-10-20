<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Commerce\CatalogImageIndexer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Fetches, validates and indexes one listing photo off the request path. */
final class IndexProductImage implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public int $uniqueFor = 300;

    public function __construct(public readonly string $productId) {}

    public function uniqueId(): string
    {
        return $this->productId;
    }

    public function handle(CatalogImageIndexer $indexer): void
    {
        $indexer->index($this->productId);
    }
}

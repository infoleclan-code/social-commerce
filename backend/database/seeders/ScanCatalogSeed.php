<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Services\Commerce\ScanCatalogSeed as CatalogPhotos;
use Illuminate\Database\Seeder;

/** Downloads catalog photos and stores the scan vectors, including price variants. */
class ScanCatalogSeed extends Seeder
{
    public function run(): void
    {
        app(CatalogPhotos::class)->run();
    }
}

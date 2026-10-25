<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(DemoCatalogSeed::class);
        $this->call(DemoAccountsSeeder::class);
        $this->call(DemoReelsSeeder::class);
    }
}

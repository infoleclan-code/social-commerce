<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Category, colors, and brand used to reject a vector neighbor that is a different product. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE products
              ADD COLUMN vision_category text,
              ADD COLUMN vision_colors text[] NOT NULL DEFAULT '{}',
              ADD COLUMN vision_brand text,
              ADD COLUMN vision_features text[] NOT NULL DEFAULT '{}';

            UPDATE products SET vision_category = 'footwear.running', vision_colors = '{red}', vision_brand = lower(brand)
              WHERE title = 'Court runner';
            UPDATE products SET vision_category = 'apparel.jacket', vision_colors = '{black,grey}', vision_brand = lower(brand)
              WHERE title = 'Night jacket';
            UPDATE products SET vision_category = 'apparel.coat', vision_colors = '{blue,grey}', vision_brand = lower(brand)
              WHERE title = 'City coat';
            UPDATE products SET vision_category = 'apparel.scarf', vision_colors = '{green}', vision_brand = lower(brand)
              WHERE title = 'Bridge scarf';
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE products
              DROP COLUMN IF EXISTS vision_category,
              DROP COLUMN IF EXISTS vision_colors,
              DROP COLUMN IF EXISTS vision_brand,
              DROP COLUMN IF EXISTS vision_features;
            SQL);
    }
};

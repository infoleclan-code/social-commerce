<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Speeds hashtag filters (`tags @> '["name"]'`) on live reels. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE INDEX feed_posts_tags_gin ON feed_posts USING gin (tags jsonb_path_ops)
              WHERE deleted_at IS NULL;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP INDEX IF EXISTS feed_posts_tags_gin');
    }
};

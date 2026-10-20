<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Core schema, authored as plain PostgreSQL in database/sql/NN_*.sql.
 *
 * Why SQL instead of the Schema builder: most guarantees live in the database
 * (triggers, deferred constraint triggers, composite/ownership foreign keys,
 * partial and functional unique indexes, NULLS NOT DISTINCT, generated
 * columns, CHECKs over jsonb) and cannot be expressed with Blueprint.
 *
 * Files run in name order inside this migration's transaction. The schema is
 * the social platform: users, follows, reels, likes, and comments. Future
 * changes go in NEW migrations.
 *
 * Requires PostgreSQL 15+.
 */
return new class extends Migration
{
    public function up(): void
    {
        $files = glob(__DIR__.'/../sql/[0-9][0-9]_*.sql');
        sort($files, SORT_STRING);

        if ($files === []) {
            throw new RuntimeException('No schema files found in database/sql.');
        }

        foreach ($files as $file) {
            DB::unprepared(file_get_contents($file));
        }
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS
                feed_post_comments, feed_post_likes, feed_posts,
                social_connections, user_consents, user_devices, users,
                status_transitions
            CASCADE;

            DROP FUNCTION IF EXISTS
                anonymize_user, enforce_status_transition, feed_post_comments_count,
                feed_post_comments_guard, feed_post_likes_count, feed_posts_rank_score,
                feed_posts_require_opt_in, forbid_mutation, gen_uuid_v7, set_updated_at;
        SQL);
    }
};

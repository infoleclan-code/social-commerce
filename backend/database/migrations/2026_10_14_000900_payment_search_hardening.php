<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A second image vector for cross-checks, and an append-only Stripe event id
 * so a webhook is applied once.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE products
              ADD COLUMN structure_embedding float8[] CHECK (
                structure_embedding IS NULL OR cardinality(structure_embedding) = 32
              );

            CREATE TABLE stripe_events (
              id           text PRIMARY KEY CHECK (char_length(id) BETWEEN 3 AND 80),
              type         text NOT NULL CHECK (char_length(type) BETWEEN 3 AND 80),
              received_at  timestamptz NOT NULL DEFAULT now()
            );
            CREATE TRIGGER stripe_events_append_only
              BEFORE UPDATE OR DELETE ON stripe_events
              FOR EACH ROW EXECUTE FUNCTION forbid_mutation();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS stripe_events;
            ALTER TABLE products DROP COLUMN IF EXISTS structure_embedding;
            SQL);
    }
};

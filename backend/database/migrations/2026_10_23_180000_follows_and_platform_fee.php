<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-way follows (separate from mutual friendship) and the platform fee the ledger reverses on refund.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE follows (
              follower_id uuid NOT NULL REFERENCES users (id) ON DELETE CASCADE,
              followee_id uuid NOT NULL REFERENCES users (id) ON DELETE CASCADE,
              created_at  timestamptz NOT NULL DEFAULT now(),
              PRIMARY KEY (follower_id, followee_id),
              CHECK (follower_id <> followee_id)
            );
            CREATE INDEX follows_followee_idx ON follows (followee_id, created_at DESC);

            ALTER TABLE orders
              ADD COLUMN platform_fee_minor bigint NOT NULL DEFAULT 0 CHECK (platform_fee_minor >= 0);

            ALTER TABLE ledger_journals DROP CONSTRAINT IF EXISTS ledger_journals_kind_check;
            ALTER TABLE ledger_journals ADD CONSTRAINT ledger_journals_kind_check CHECK (kind IN (
              'sale', 'commission_pending', 'commission_release', 'commission_reverse', 'refund',
              'price_protection', 'price_protection_reverse', 'platform_fee', 'platform_fee_reverse'
            ));
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE ledger_journals DROP CONSTRAINT IF EXISTS ledger_journals_kind_check;
            ALTER TABLE ledger_journals ADD CONSTRAINT ledger_journals_kind_check CHECK (kind IN (
              'sale', 'commission_pending', 'commission_release', 'commission_reverse', 'refund',
              'price_protection', 'price_protection_reverse'
            ));
            ALTER TABLE orders DROP COLUMN IF EXISTS platform_fee_minor;
            DROP TABLE IF EXISTS follows;
            SQL);
    }
};

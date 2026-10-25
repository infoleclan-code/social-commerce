<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Admin role, moderation flags, and auction scheduling.
 * Seller status changes follow status_transitions. Hidden posts and comments stay in the database.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE users
              ADD COLUMN role text NOT NULL DEFAULT 'member' CHECK (role IN ('member', 'admin')),
              ADD COLUMN totp_secret text;

            ALTER TABLE feed_posts
              ADD COLUMN hidden_at timestamptz,
              ADD COLUMN hidden_by uuid REFERENCES users (id);

            ALTER TABLE feed_post_comments
              ADD COLUMN hidden_at timestamptz,
              ADD COLUMN hidden_by uuid REFERENCES users (id);

            ALTER TABLE auctions
              ADD COLUMN starts_at timestamptz NOT NULL DEFAULT now(),
              ADD COLUMN ended_at timestamptz;

            INSERT INTO status_transitions (table_name, from_status, to_status) VALUES
              ('sellers', 'pending', 'verified'),
              ('sellers', 'pending', 'rejected'),
              ('sellers', 'verified', 'pending'),
              ('sellers', 'verified', 'rejected'),
              ('sellers', 'rejected', 'pending'),
              ('sellers', 'rejected', 'verified')
            ON CONFLICT DO NOTHING;

            CREATE TRIGGER sellers_status_transition
              BEFORE UPDATE OF status ON sellers
              FOR EACH ROW EXECUTE FUNCTION enforce_status_transition();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS sellers_status_transition ON sellers;
            DELETE FROM status_transitions WHERE table_name = 'sellers';
            ALTER TABLE auctions DROP COLUMN IF EXISTS starts_at, DROP COLUMN IF EXISTS ended_at;
            ALTER TABLE feed_post_comments DROP COLUMN IF EXISTS hidden_at, DROP COLUMN IF EXISTS hidden_by;
            ALTER TABLE feed_posts DROP COLUMN IF EXISTS hidden_at, DROP COLUMN IF EXISTS hidden_by;
            ALTER TABLE users DROP COLUMN IF EXISTS role, DROP COLUMN IF EXISTS totp_secret;
            SQL);
    }
};

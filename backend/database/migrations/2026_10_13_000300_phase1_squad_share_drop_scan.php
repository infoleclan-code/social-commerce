<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 1: squad-price authorizations, share-and-earn ledger, drop-hour
 * reservations, and visual-scan records. Money stays integer minor units.
 * Card numbers are never columns on these tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE products
              ADD COLUMN squad_price_minor bigint,
              ADD COLUMN slug text;

            UPDATE products
            SET squad_price_minor = (price_minor * 70) / 100,
                slug = trim(both '-' from lower(regexp_replace(title, '[^a-zA-Z0-9]+', '-', 'g')));

            ALTER TABLE products
              ALTER COLUMN squad_price_minor SET NOT NULL,
              ALTER COLUMN slug SET NOT NULL;

            ALTER TABLE products
              ADD CONSTRAINT products_squad_price_check CHECK (squad_price_minor >= 0 AND squad_price_minor <= price_minor),
              ADD CONSTRAINT products_slug_unique UNIQUE (slug);

            DO $$
            DECLARE cname text;
            BEGIN
              SELECT con.conname INTO cname
              FROM pg_constraint con
              WHERE con.conrelid = 'orders'::regclass
                AND con.contype = 'c'
                AND pg_get_constraintdef(con.oid) ILIKE '%status%';
              IF cname IS NOT NULL THEN
                EXECUTE format('ALTER TABLE orders DROP CONSTRAINT %I', cname);
              END IF;
            END $$;

            ALTER TABLE orders
              ADD CONSTRAINT orders_status_check CHECK (status IN ('processing', 'paid', 'cancelled'));

            CREATE TABLE payment_authorizations (
              id                 uuid PRIMARY KEY DEFAULT gen_uuid_v7(),
              buyer_id           uuid NOT NULL REFERENCES users (id),
              product_id         uuid NOT NULL REFERENCES products (id),
              order_id           uuid REFERENCES orders (id),
              squad_id           uuid REFERENCES squads (id),
              purpose            text NOT NULL CHECK (purpose IN ('squad', 'one_tap', 'drop')),
              status             text NOT NULL CHECK (status IN ('authorized', 'captured', 'voided')),
              authorized_minor   bigint NOT NULL CHECK (authorized_minor >= 0),
              captured_minor     bigint CHECK (captured_minor IS NULL OR captured_minor >= 0),
              currency           char(3) NOT NULL,
              idempotency_key    text NOT NULL,
              expires_at         timestamptz NOT NULL,
              created_at         timestamptz NOT NULL DEFAULT now(),
              UNIQUE (buyer_id, idempotency_key)
            );
            CREATE INDEX payment_authorizations_open_idx
              ON payment_authorizations (expires_at) WHERE status = 'authorized';

            CREATE TABLE referral_links (
              id              uuid PRIMARY KEY DEFAULT gen_uuid_v7(),
              token           text NOT NULL UNIQUE CHECK (token ~ '^[A-Za-z0-9]{10,32}$'),
              user_id         uuid NOT NULL REFERENCES users (id),
              post_id         uuid REFERENCES feed_posts (id) ON DELETE SET NULL,
              product_id      uuid NOT NULL REFERENCES products (id),
              commission_bps  integer NOT NULL CHECK (commission_bps BETWEEN 0 AND 5000),
              created_at      timestamptz NOT NULL DEFAULT now()
            );
            CREATE INDEX referral_links_user_idx ON referral_links (user_id, created_at DESC);

            CREATE TABLE referral_clicks (
              id                uuid PRIMARY KEY DEFAULT gen_uuid_v7(),
              referral_link_id  uuid NOT NULL REFERENCES referral_links (id) ON DELETE CASCADE,
              viewer_id         uuid REFERENCES users (id),
              created_at        timestamptz NOT NULL DEFAULT now()
            );
            CREATE INDEX referral_clicks_link_idx ON referral_clicks (referral_link_id, created_at DESC);

            CREATE TABLE commission_ledger (
              id                uuid PRIMARY KEY DEFAULT gen_uuid_v7(),
              user_id           uuid NOT NULL REFERENCES users (id),
              referral_link_id  uuid NOT NULL REFERENCES referral_links (id),
              order_id          uuid REFERENCES orders (id),
              entry             text NOT NULL CHECK (entry IN ('earn', 'reverse')),
              amount_minor      bigint NOT NULL CHECK (amount_minor > 0),
              currency          char(3) NOT NULL,
              created_at        timestamptz NOT NULL DEFAULT now()
            );
            CREATE TRIGGER commission_ledger_append_only
              BEFORE UPDATE OR DELETE ON commission_ledger
              FOR EACH ROW EXECUTE FUNCTION forbid_mutation();
            CREATE INDEX commission_ledger_user_idx ON commission_ledger (user_id, created_at DESC);

            CREATE TABLE drop_hours (
              id           uuid PRIMARY KEY DEFAULT gen_uuid_v7(),
              product_id   uuid NOT NULL REFERENCES products (id),
              price_minor  bigint NOT NULL CHECK (price_minor >= 0),
              stock        integer NOT NULL CHECK (stock >= 0),
              reserved     integer NOT NULL DEFAULT 0 CHECK (reserved >= 0 AND reserved <= stock),
              starts_at    timestamptz NOT NULL,
              ends_at      timestamptz NOT NULL,
              created_at   timestamptz NOT NULL DEFAULT now(),
              CHECK (ends_at > starts_at)
            );
            CREATE INDEX drop_hours_live_idx ON drop_hours (product_id, ends_at);

            CREATE TABLE drop_reservations (
              id            uuid PRIMARY KEY DEFAULT gen_uuid_v7(),
              drop_hour_id  uuid NOT NULL REFERENCES drop_hours (id) ON DELETE CASCADE,
              user_id       uuid NOT NULL REFERENCES users (id),
              quantity      integer NOT NULL CHECK (quantity BETWEEN 1 AND 3),
              status        text NOT NULL CHECK (status IN ('reserved', 'converted', 'released')),
              expires_at    timestamptz NOT NULL,
              created_at    timestamptz NOT NULL DEFAULT now(),
              UNIQUE (drop_hour_id, user_id)
            );

            CREATE TABLE visual_scans (
              id                   uuid PRIMARY KEY DEFAULT gen_uuid_v7(),
              user_id              uuid NOT NULL REFERENCES users (id),
              image_sha256         text NOT NULL CHECK (char_length(image_sha256) = 64),
              analyzer             text NOT NULL,
              detected_category    text,
              labels               jsonb NOT NULL DEFAULT '[]'::jsonb,
              bounding_box         jsonb,
              matched_product_ids  uuid[] NOT NULL DEFAULT '{}',
              fallback             boolean NOT NULL DEFAULT false,
              latency_ms           integer CHECK (latency_ms IS NULL OR latency_ms >= 0),
              created_at           timestamptz NOT NULL DEFAULT now()
            );
            CREATE INDEX visual_scans_hash_idx ON visual_scans (image_sha256, created_at DESC);
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS visual_scans, drop_reservations, drop_hours,
              commission_ledger, referral_clicks, referral_links, payment_authorizations;
            ALTER TABLE products DROP CONSTRAINT IF EXISTS products_squad_price_check;
            ALTER TABLE products DROP CONSTRAINT IF EXISTS products_slug_unique;
            ALTER TABLE products DROP COLUMN IF EXISTS squad_price_minor, DROP COLUMN IF EXISTS slug;
            SQL);
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Shoppable reels, flash drops, squad buys, rewards, and affiliate credits.
 * Money is integer minor units. Card numbers are never stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE products (
              id              uuid PRIMARY KEY DEFAULT gen_uuid_v7(),
              title           text NOT NULL CHECK (char_length(title) BETWEEN 1 AND 160),
              brand           text NOT NULL CHECK (char_length(brand) BETWEEN 1 AND 80),
              image_url       text NOT NULL CHECK (char_length(image_url) <= 500),
              price_minor     bigint NOT NULL CHECK (price_minor >= 0),
              currency        char(3) NOT NULL DEFAULT 'USD' CHECK (currency ~ '^[A-Z]{3}$'),
              stock           integer NOT NULL DEFAULT 0 CHECK (stock >= 0),
              try_on_enabled  boolean NOT NULL DEFAULT false,
              created_at      timestamptz NOT NULL DEFAULT now(),
              updated_at      timestamptz NOT NULL DEFAULT now()
            );
            CREATE TRIGGER products_set_updated_at BEFORE UPDATE ON products
              FOR EACH ROW EXECUTE FUNCTION set_updated_at();

            CREATE TABLE reel_product_tags (
              post_id          uuid NOT NULL REFERENCES feed_posts (id) ON DELETE CASCADE,
              product_id       uuid NOT NULL REFERENCES products (id),
              commission_bps   integer NOT NULL DEFAULT 800 CHECK (commission_bps BETWEEN 0 AND 5000),
              created_at       timestamptz NOT NULL DEFAULT now(),
              PRIMARY KEY (post_id, product_id)
            );
            CREATE INDEX reel_product_tags_product_idx ON reel_product_tags (product_id);

            CREATE TABLE flash_drops (
              id                uuid PRIMARY KEY DEFAULT gen_uuid_v7(),
              product_id        uuid NOT NULL REFERENCES products (id),
              discount_bps      integer NOT NULL CHECK (discount_bps BETWEEN 1 AND 8000),
              stock_remaining   integer NOT NULL CHECK (stock_remaining >= 0),
              starts_at         timestamptz NOT NULL DEFAULT now(),
              ends_at           timestamptz NOT NULL,
              created_at        timestamptz NOT NULL DEFAULT now(),
              CHECK (ends_at > starts_at)
            );
            CREATE INDEX flash_drops_live_idx ON flash_drops (product_id, ends_at) WHERE stock_remaining > 0;

            CREATE TABLE user_wallets (
              user_id      uuid PRIMARY KEY REFERENCES users (id) ON DELETE CASCADE,
              coins        integer NOT NULL DEFAULT 0 CHECK (coins >= 0),
              xp           integer NOT NULL DEFAULT 0 CHECK (xp >= 0),
              cash_minor   bigint NOT NULL DEFAULT 0 CHECK (cash_minor >= 0),
              currency     char(3) NOT NULL DEFAULT 'USD',
              updated_at   timestamptz NOT NULL DEFAULT now()
            );

            CREATE TABLE reward_events (
              id          uuid PRIMARY KEY DEFAULT gen_uuid_v7(),
              user_id     uuid NOT NULL REFERENCES users (id) ON DELETE CASCADE,
              kind        text NOT NULL CHECK (kind IN ('watch', 'like', 'comment', 'purchase', 'loot', 'affiliate')),
              coins       integer NOT NULL DEFAULT 0,
              xp          integer NOT NULL DEFAULT 0,
              cash_minor  bigint NOT NULL DEFAULT 0,
              source_id   uuid,
              created_at  timestamptz NOT NULL DEFAULT now()
            );
            CREATE UNIQUE INDEX reward_events_once_idx ON reward_events (user_id, kind, source_id)
              WHERE source_id IS NOT NULL AND kind IN ('watch', 'like', 'comment');

            CREATE TABLE coupons (
              id             uuid PRIMARY KEY DEFAULT gen_uuid_v7(),
              user_id        uuid NOT NULL REFERENCES users (id) ON DELETE CASCADE,
              label          text NOT NULL,
              discount_bps   integer NOT NULL CHECK (discount_bps BETWEEN 1 AND 8000),
              expires_at     timestamptz NOT NULL,
              redeemed_at    timestamptz,
              created_at     timestamptz NOT NULL DEFAULT now()
            );
            CREATE INDEX coupons_open_idx ON coupons (user_id, expires_at) WHERE redeemed_at IS NULL;

            CREATE TABLE squads (
              id                       uuid PRIMARY KEY DEFAULT gen_uuid_v7(),
              product_id               uuid NOT NULL REFERENCES products (id),
              host_id                  uuid NOT NULL REFERENCES users (id),
              post_id                  uuid REFERENCES feed_posts (id) ON DELETE SET NULL,
              discount_bps_per_member  integer NOT NULL DEFAULT 500 CHECK (discount_bps_per_member BETWEEN 0 AND 3000),
              max_members              integer NOT NULL DEFAULT 5 CHECK (max_members BETWEEN 2 AND 20),
              floor_price_minor        bigint NOT NULL CHECK (floor_price_minor >= 0),
              expires_at               timestamptz NOT NULL,
              created_at               timestamptz NOT NULL DEFAULT now()
            );

            CREATE TABLE squad_members (
              squad_id   uuid NOT NULL REFERENCES squads (id) ON DELETE CASCADE,
              user_id    uuid NOT NULL REFERENCES users (id) ON DELETE CASCADE,
              joined_at  timestamptz NOT NULL DEFAULT now(),
              PRIMARY KEY (squad_id, user_id)
            );

            CREATE TABLE orders (
              id                 uuid PRIMARY KEY DEFAULT gen_uuid_v7(),
              buyer_id           uuid NOT NULL REFERENCES users (id),
              product_id         uuid NOT NULL REFERENCES products (id),
              post_id            uuid REFERENCES feed_posts (id) ON DELETE SET NULL,
              creator_id         uuid REFERENCES users (id),
              flash_drop_id      uuid REFERENCES flash_drops (id),
              squad_id           uuid REFERENCES squads (id),
              coupon_id          uuid REFERENCES coupons (id),
              quantity           integer NOT NULL CHECK (quantity BETWEEN 1 AND 5),
              unit_price_minor   bigint NOT NULL CHECK (unit_price_minor >= 0),
              discount_bps       integer NOT NULL DEFAULT 0 CHECK (discount_bps BETWEEN 0 AND 8000),
              total_minor        bigint NOT NULL CHECK (total_minor >= 0),
              currency           char(3) NOT NULL,
              status             text NOT NULL DEFAULT 'paid' CHECK (status IN ('paid', 'cancelled')),
              idempotency_key    text NOT NULL,
              created_at         timestamptz NOT NULL DEFAULT now(),
              UNIQUE (buyer_id, idempotency_key)
            );
            CREATE INDEX orders_recent_idx ON orders (created_at DESC);

            CREATE TABLE activity_events (
              id          uuid PRIMARY KEY DEFAULT gen_uuid_v7(),
              actor_id    uuid NOT NULL REFERENCES users (id),
              kind        text NOT NULL CHECK (kind IN ('purchase')),
              product_id  uuid NOT NULL REFERENCES products (id),
              post_id     uuid REFERENCES feed_posts (id) ON DELETE SET NULL,
              created_at  timestamptz NOT NULL DEFAULT now()
            );
            CREATE INDEX activity_events_recent_idx ON activity_events (created_at DESC);

            ALTER TABLE feed_posts
              ADD COLUMN watch_completions integer NOT NULL DEFAULT 0 CHECK (watch_completions >= 0);

            CREATE OR REPLACE FUNCTION feed_posts_rank_score() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              NEW.rank_score := log(greatest(
                  NEW.likes_count
                  + 2 * NEW.comments_count
                  + 3 * NEW.shares_count
                  + 4 * NEW.watch_completions, 1)::double precision)
                + extract(epoch FROM NEW.created_at)::double precision / 45000.0;
              RETURN NEW;
            END $$;

            DROP TRIGGER IF EXISTS feed_posts_rank_score ON feed_posts;
            CREATE TRIGGER feed_posts_rank_score
              BEFORE INSERT OR UPDATE OF likes_count, comments_count, shares_count, watch_completions, created_at
              ON feed_posts
              FOR EACH ROW EXECUTE FUNCTION feed_posts_rank_score();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS activity_events, orders, squad_members, squads, coupons,
              reward_events, user_wallets, flash_drops, reel_product_tags, products CASCADE;
            ALTER TABLE feed_posts DROP COLUMN IF EXISTS watch_completions;
            SQL);
    }
};

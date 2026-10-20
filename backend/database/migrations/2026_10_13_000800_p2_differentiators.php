<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Auto product tags, labeled synthetic tracks, price-drop refunds, and verified reviews.
 * A review or an owner answer is refused unless a paid order matches the writer.
 * A synthetic track cannot be stored without the AI-generated label.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE reel_product_tags
              ADD COLUMN source text NOT NULL DEFAULT 'manual' CHECK (source IN ('manual', 'auto'));

            CREATE TABLE product_tag_suggestions (
              post_id     uuid NOT NULL REFERENCES feed_posts (id) ON DELETE CASCADE,
              product_id  uuid NOT NULL REFERENCES products (id),
              score       double precision NOT NULL CHECK (score >= 0 AND score <= 1),
              status      text NOT NULL CHECK (status IN ('suggested', 'attached', 'dismissed')),
              created_at  timestamptz NOT NULL DEFAULT now(),
              PRIMARY KEY (post_id, product_id)
            );

            CREATE TABLE synthetic_tracks (
              id          uuid PRIMARY KEY DEFAULT gen_uuid_v7(),
              post_id     uuid NOT NULL REFERENCES feed_posts (id) ON DELETE CASCADE,
              language    text NOT NULL CHECK (language ~ '^[a-z]{2}$'),
              kind        text NOT NULL CHECK (kind IN ('dub', 'subtitle')),
              label       text NOT NULL DEFAULT 'AI-generated' CHECK (label = 'AI-generated'),
              disclosed   boolean NOT NULL DEFAULT true CHECK (disclosed),
              cues        text NOT NULL CHECK (position('AI-generated' in cues) > 0),
              created_at  timestamptz NOT NULL DEFAULT now(),
              UNIQUE (post_id, language, kind)
            );

            ALTER TABLE ledger_journals DROP CONSTRAINT IF EXISTS ledger_journals_kind_check;
            ALTER TABLE ledger_journals ADD CONSTRAINT ledger_journals_kind_check CHECK (kind IN (
              'sale', 'commission_pending', 'commission_release', 'commission_reverse', 'refund', 'price_protection'
            ));

            CREATE TABLE price_protections (
              order_id          uuid PRIMARY KEY REFERENCES orders (id),
              paid_unit_minor   bigint NOT NULL CHECK (paid_unit_minor >= 0),
              quantity          integer NOT NULL CHECK (quantity >= 1),
              currency          char(3) NOT NULL,
              refunded_minor    bigint NOT NULL DEFAULT 0 CHECK (refunded_minor >= 0),
              protected_until   timestamptz NOT NULL,
              created_at        timestamptz NOT NULL DEFAULT now()
            );

            CREATE TABLE item_reviews (
              id          uuid PRIMARY KEY DEFAULT gen_uuid_v7(),
              product_id  uuid NOT NULL REFERENCES products (id),
              order_id    uuid NOT NULL UNIQUE REFERENCES orders (id),
              author_id   uuid NOT NULL REFERENCES users (id),
              body        text NOT NULL CHECK (char_length(body) BETWEEN 1 AND 500),
              video_path  text,
              created_at  timestamptz NOT NULL DEFAULT now()
            );

            CREATE OR REPLACE FUNCTION item_reviews_verified_buyer() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              IF NOT EXISTS (
                SELECT 1 FROM orders
                 WHERE id = NEW.order_id
                   AND buyer_id = NEW.author_id
                   AND product_id = NEW.product_id
                   AND status = 'paid'
              ) THEN
                RAISE EXCEPTION 'review requires a verified purchase';
              END IF;
              RETURN NEW;
            END $$;
            CREATE TRIGGER item_reviews_verified_buyer
              BEFORE INSERT ON item_reviews
              FOR EACH ROW EXECUTE FUNCTION item_reviews_verified_buyer();

            CREATE TABLE item_questions (
              id          uuid PRIMARY KEY DEFAULT gen_uuid_v7(),
              product_id  uuid NOT NULL REFERENCES products (id),
              asker_id    uuid NOT NULL REFERENCES users (id),
              body        text NOT NULL CHECK (char_length(body) BETWEEN 1 AND 500),
              created_at  timestamptz NOT NULL DEFAULT now()
            );

            CREATE TABLE item_answers (
              id           uuid PRIMARY KEY DEFAULT gen_uuid_v7(),
              question_id  uuid NOT NULL UNIQUE REFERENCES item_questions (id) ON DELETE CASCADE,
              owner_id     uuid NOT NULL REFERENCES users (id),
              body         text NOT NULL CHECK (char_length(body) BETWEEN 1 AND 500),
              created_at   timestamptz NOT NULL DEFAULT now()
            );

            CREATE OR REPLACE FUNCTION item_answers_verified_owner() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              IF NOT EXISTS (
                SELECT 1
                  FROM item_questions q
                  JOIN orders o ON o.product_id = q.product_id
                 WHERE q.id = NEW.question_id
                   AND o.buyer_id = NEW.owner_id
                   AND o.status = 'paid'
              ) THEN
                RAISE EXCEPTION 'answer requires a verified purchase';
              END IF;
              RETURN NEW;
            END $$;
            CREATE TRIGGER item_answers_verified_owner
              BEFORE INSERT ON item_answers
              FOR EACH ROW EXECUTE FUNCTION item_answers_verified_owner();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS item_answers_verified_owner ON item_answers;
            DROP FUNCTION IF EXISTS item_answers_verified_owner();
            DROP TABLE IF EXISTS item_answers, item_questions;
            DROP TRIGGER IF EXISTS item_reviews_verified_buyer ON item_reviews;
            DROP FUNCTION IF EXISTS item_reviews_verified_buyer();
            DROP TABLE IF EXISTS item_reviews, price_protections, synthetic_tracks, product_tag_suggestions;
            ALTER TABLE reel_product_tags DROP COLUMN IF EXISTS source;
            ALTER TABLE ledger_journals DROP CONSTRAINT IF EXISTS ledger_journals_kind_check;
            ALTER TABLE ledger_journals ADD CONSTRAINT ledger_journals_kind_check CHECK (kind IN (
              'sale', 'commission_pending', 'commission_release', 'commission_reverse', 'refund'
            ));
            SQL);
    }
};

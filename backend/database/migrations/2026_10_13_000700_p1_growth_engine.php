<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Personalized taste weights, seller accounts, and landed-cost inputs.
 * Prices stay integer minor units. Stripe Connect ids are account ids, not card numbers.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE taste_profiles (
              user_id       uuid PRIMARY KEY REFERENCES users (id) ON DELETE CASCADE,
              styles        text[] NOT NULL CHECK (cardinality(styles) BETWEEN 1 AND 6),
              completed_at  timestamptz NOT NULL DEFAULT now()
            );

            CREATE TABLE viewer_tag_weights (
              user_id  uuid NOT NULL REFERENCES users (id) ON DELETE CASCADE,
              tag      text NOT NULL CHECK (char_length(tag) BETWEEN 1 AND 40),
              weight   integer NOT NULL DEFAULT 0 CHECK (weight >= 0),
              PRIMARY KEY (user_id, tag)
            );

            CREATE TABLE product_saves (
              user_id     uuid NOT NULL REFERENCES users (id) ON DELETE CASCADE,
              product_id  uuid NOT NULL REFERENCES products (id) ON DELETE CASCADE,
              created_at  timestamptz NOT NULL DEFAULT now(),
              PRIMARY KEY (user_id, product_id)
            );

            CREATE TABLE sellers (
              user_id               uuid PRIMARY KEY REFERENCES users (id) ON DELETE CASCADE,
              legal_name            text NOT NULL CHECK (char_length(legal_name) BETWEEN 2 AND 160),
              country               char(2) NOT NULL CHECK (country ~ '^[A-Z]{2}$'),
              registration_number   text NOT NULL CHECK (char_length(registration_number) BETWEEN 2 AND 40),
              status                text NOT NULL CHECK (status IN ('pending', 'verified', 'rejected')),
              stripe_account_id     text UNIQUE,
              charges_enabled       boolean NOT NULL DEFAULT false,
              payouts_enabled       boolean NOT NULL DEFAULT false,
              created_at            timestamptz NOT NULL DEFAULT now(),
              updated_at            timestamptz NOT NULL DEFAULT now()
            );
            CREATE TRIGGER sellers_set_updated_at BEFORE UPDATE ON sellers
              FOR EACH ROW EXECUTE FUNCTION set_updated_at();

            ALTER TABLE products
              ADD COLUMN seller_id uuid REFERENCES users (id),
              ADD COLUMN sku text,
              ADD COLUMN ships_from char(2) NOT NULL DEFAULT 'US' CHECK (ships_from ~ '^[A-Z]{2}$'),
              ADD COLUMN shipping_minor bigint NOT NULL DEFAULT 500 CHECK (shipping_minor >= 0),
              ADD COLUMN vat_bps integer NOT NULL DEFAULT 2000 CHECK (vat_bps BETWEEN 0 AND 3000),
              ADD COLUMN duty_bps integer NOT NULL DEFAULT 400 CHECK (duty_bps BETWEEN 0 AND 3000),
              ADD COLUMN handling_days integer NOT NULL DEFAULT 2 CHECK (handling_days BETWEEN 0 AND 21);
            CREATE UNIQUE INDEX products_seller_sku_uidx ON products (seller_id, sku)
              WHERE seller_id IS NOT NULL AND sku IS NOT NULL;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS products_seller_sku_uidx;
            ALTER TABLE products
              DROP COLUMN IF EXISTS seller_id, DROP COLUMN IF EXISTS sku, DROP COLUMN IF EXISTS ships_from,
              DROP COLUMN IF EXISTS shipping_minor, DROP COLUMN IF EXISTS vat_bps,
              DROP COLUMN IF EXISTS duty_bps, DROP COLUMN IF EXISTS handling_days;
            DROP TABLE IF EXISTS sellers, product_saves, viewer_tag_weights, taste_profiles;
            SQL);
    }
};

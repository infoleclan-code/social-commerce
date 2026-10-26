<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gift street addresses are ciphertext only. Auction bids store the card hold that
 * backed them so a later bid can release the previous leader's authorization.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE gifts ADD COLUMN address_ciphertext text;

            ALTER TABLE auctions ADD COLUMN leader_payment_intent_id text;
            ALTER TABLE auction_bids ADD COLUMN payment_intent_id text;

            CREATE TABLE shipping_addresses (
              id                   uuid PRIMARY KEY,
              user_id              uuid NOT NULL REFERENCES users (id) ON DELETE CASCADE,
              label                text CHECK (label IS NULL OR char_length(label) BETWEEN 1 AND 40),
              country              char(2) NOT NULL CHECK (country ~ '^[A-Z]{2}$'),
              address_ciphertext   text NOT NULL,
              is_default           boolean NOT NULL DEFAULT false,
              created_at           timestamptz NOT NULL DEFAULT now(),
              updated_at           timestamptz NOT NULL DEFAULT now()
            );
            CREATE INDEX shipping_addresses_user_idx ON shipping_addresses (user_id, created_at DESC);
            CREATE UNIQUE INDEX shipping_addresses_one_default ON shipping_addresses (user_id) WHERE is_default;
            CREATE TRIGGER shipping_addresses_set_updated_at BEFORE UPDATE ON shipping_addresses
              FOR EACH ROW EXECUTE FUNCTION set_updated_at();

            CREATE TABLE cart_items (
              user_id     uuid NOT NULL REFERENCES users (id) ON DELETE CASCADE,
              product_id  uuid NOT NULL REFERENCES products (id) ON DELETE CASCADE,
              quantity    integer NOT NULL CHECK (quantity BETWEEN 1 AND 3),
              created_at  timestamptz NOT NULL DEFAULT now(),
              updated_at  timestamptz NOT NULL DEFAULT now(),
              PRIMARY KEY (user_id, product_id)
            );
            CREATE TRIGGER cart_items_set_updated_at BEFORE UPDATE ON cart_items
              FOR EACH ROW EXECUTE FUNCTION set_updated_at();

            ALTER TABLE orders
              ADD COLUMN shipping_address_id uuid REFERENCES shipping_addresses (id),
              ADD COLUMN carrier text CHECK (carrier IS NULL OR char_length(carrier) BETWEEN 2 AND 40),
              ADD COLUMN tracking_number text CHECK (tracking_number IS NULL OR char_length(tracking_number) BETWEEN 4 AND 64),
              ADD COLUMN shipped_at timestamptz;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE orders DROP COLUMN IF EXISTS shipped_at, DROP COLUMN IF EXISTS tracking_number,
              DROP COLUMN IF EXISTS carrier, DROP COLUMN IF EXISTS shipping_address_id;
            DROP TABLE IF EXISTS cart_items, shipping_addresses;
            ALTER TABLE auction_bids DROP COLUMN IF EXISTS payment_intent_id;
            ALTER TABLE auctions DROP COLUMN IF EXISTS leader_payment_intent_id;
            ALTER TABLE gifts DROP COLUMN IF EXISTS address_ciphertext;
            SQL);
    }
};

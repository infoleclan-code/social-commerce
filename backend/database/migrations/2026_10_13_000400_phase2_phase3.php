<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2 habit loops and phase 3 scale loops.
 * Gift addresses are written only by the recipient and are not part of the buyer payload.
 * Money is integer minor units. Card numbers are not stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE gifts (
              id             uuid PRIMARY KEY DEFAULT gen_uuid_v7(),
              buyer_id       uuid NOT NULL REFERENCES users (id),
              product_id     uuid NOT NULL REFERENCES products (id),
              post_id        uuid REFERENCES feed_posts (id) ON DELETE SET NULL,
              order_id       uuid NOT NULL REFERENCES orders (id),
              token          text NOT NULL UNIQUE CHECK (token ~ '^[A-Za-z0-9]{10,32}$'),
              status         text NOT NULL CHECK (status IN ('paid', 'claimed')),
              claimed_by     uuid REFERENCES users (id),
              address_line   text CHECK (address_line IS NULL OR char_length(address_line) BETWEEN 4 AND 120),
              locality       text CHECK (locality IS NULL OR char_length(locality) BETWEEN 2 AND 80),
              postal_code    text CHECK (postal_code IS NULL OR char_length(postal_code) BETWEEN 2 AND 16),
              country        char(2) CHECK (country IS NULL OR country ~ '^[A-Z]{2}$'),
              expires_at     timestamptz NOT NULL,
              claimed_at     timestamptz,
              created_at     timestamptz NOT NULL DEFAULT now(),
              CHECK (status = 'paid' OR claimed_by IS NOT NULL)
            );
            CREATE INDEX gifts_buyer_idx ON gifts (buyer_id, created_at DESC);

            CREATE TABLE ritual_checkins (
              user_id     uuid NOT NULL REFERENCES users (id) ON DELETE CASCADE,
              day         date NOT NULL,
              streak      integer NOT NULL CHECK (streak >= 1),
              created_at  timestamptz NOT NULL DEFAULT now(),
              PRIMARY KEY (user_id, day)
            );

            CREATE TABLE ritual_missions (
              user_id      uuid NOT NULL REFERENCES users (id) ON DELETE CASCADE,
              day          date NOT NULL,
              kind         text NOT NULL CHECK (kind IN ('watch', 'like', 'share')),
              created_at   timestamptz NOT NULL DEFAULT now(),
              PRIMARY KEY (user_id, day, kind)
            );

            CREATE TABLE prize_spins (
              id           uuid PRIMARY KEY DEFAULT gen_uuid_v7(),
              user_id      uuid NOT NULL REFERENCES users (id) ON DELETE CASCADE,
              day          date NOT NULL,
              coupon_id    uuid NOT NULL REFERENCES coupons (id),
              discount_bps integer NOT NULL CHECK (discount_bps IN (1000, 1500, 2000, 3000)),
              created_at   timestamptz NOT NULL DEFAULT now(),
              UNIQUE (user_id, day)
            );

            CREATE TABLE try_on_renders (
              id            uuid PRIMARY KEY DEFAULT gen_uuid_v7(),
              user_id       uuid NOT NULL REFERENCES users (id) ON DELETE CASCADE,
              product_id    uuid NOT NULL REFERENCES products (id),
              photo_sha256  text CHECK (photo_sha256 IS NULL OR char_length(photo_sha256) = 64),
              renderer      text NOT NULL,
              image_url     text NOT NULL,
              created_at    timestamptz NOT NULL DEFAULT now()
            );

            CREATE TABLE try_on_polls (
              id          uuid PRIMARY KEY DEFAULT gen_uuid_v7(),
              render_id   uuid NOT NULL REFERENCES try_on_renders (id) ON DELETE CASCADE,
              host_id     uuid NOT NULL REFERENCES users (id),
              token       text NOT NULL UNIQUE CHECK (token ~ '^[A-Za-z0-9]{10,32}$'),
              created_at  timestamptz NOT NULL DEFAULT now()
            );

            CREATE TABLE try_on_votes (
              poll_id     uuid NOT NULL REFERENCES try_on_polls (id) ON DELETE CASCADE,
              user_id     uuid NOT NULL REFERENCES users (id) ON DELETE CASCADE,
              choice      text NOT NULL CHECK (choice IN ('wear', 'skip')),
              created_at  timestamptz NOT NULL DEFAULT now(),
              PRIMARY KEY (poll_id, user_id)
            );

            CREATE TABLE closet_items (
              id                  uuid PRIMARY KEY DEFAULT gen_uuid_v7(),
              user_id             uuid NOT NULL REFERENCES users (id) ON DELETE CASCADE,
              order_id            uuid NOT NULL UNIQUE REFERENCES orders (id),
              product_id          uuid NOT NULL REFERENCES products (id),
              status              text NOT NULL CHECK (status IN ('owned', 'listed')),
              listing_price_minor bigint CHECK (listing_price_minor IS NULL OR listing_price_minor >= 0),
              listed_at           timestamptz,
              created_at          timestamptz NOT NULL DEFAULT now()
            );
            CREATE INDEX closet_items_user_idx ON closet_items (user_id, created_at DESC);

            CREATE TABLE auctions (
              id             uuid PRIMARY KEY DEFAULT gen_uuid_v7(),
              product_id     uuid NOT NULL REFERENCES products (id),
              start_minor    bigint NOT NULL CHECK (start_minor >= 0),
              current_minor  bigint NOT NULL CHECK (current_minor >= start_minor),
              leader_id      uuid REFERENCES users (id),
              ends_at        timestamptz NOT NULL,
              created_at     timestamptz NOT NULL DEFAULT now()
            );
            CREATE INDEX auctions_live_idx ON auctions (ends_at);

            CREATE TABLE auction_bids (
              id           uuid PRIMARY KEY DEFAULT gen_uuid_v7(),
              auction_id   uuid NOT NULL REFERENCES auctions (id) ON DELETE CASCADE,
              bidder_id    uuid NOT NULL REFERENCES users (id),
              amount_minor bigint NOT NULL CHECK (amount_minor > 0),
              created_at   timestamptz NOT NULL DEFAULT now()
            );
            CREATE INDEX auction_bids_auction_idx ON auction_bids (auction_id, created_at DESC);

            CREATE TABLE blind_boxes (
              id           uuid PRIMARY KEY DEFAULT gen_uuid_v7(),
              title        text NOT NULL,
              price_minor  bigint NOT NULL CHECK (price_minor >= 0),
              currency     char(3) NOT NULL DEFAULT 'USD',
              created_at   timestamptz NOT NULL DEFAULT now()
            );

            CREATE TABLE blind_box_odds (
              box_id       uuid NOT NULL REFERENCES blind_boxes (id) ON DELETE CASCADE,
              product_id   uuid NOT NULL REFERENCES products (id),
              weight_bps   integer NOT NULL CHECK (weight_bps BETWEEN 1 AND 10000),
              PRIMARY KEY (box_id, product_id)
            );

            CREATE TABLE blind_pulls (
              id          uuid PRIMARY KEY DEFAULT gen_uuid_v7(),
              box_id      uuid NOT NULL REFERENCES blind_boxes (id),
              user_id     uuid NOT NULL REFERENCES users (id),
              product_id  uuid NOT NULL REFERENCES products (id),
              pulled_on   date NOT NULL,
              created_at  timestamptz NOT NULL DEFAULT now(),
              UNIQUE (user_id, pulled_on)
            );
            SQL);

        $products = DB::table('products')->orderBy('title')->limit(3)->get();
        if ($products->count() === 3) {
            $boxId = (string) \Illuminate\Support\Str::uuid7();
            DB::table('blind_boxes')->insert([
                'id' => $boxId,
                'title' => 'Collector drop',
                'price_minor' => 2500,
                'currency' => 'USD',
                'created_at' => now(),
            ]);
            $weights = [5000, 3000, 2000];
            foreach ($products->values() as $index => $product) {
                DB::table('blind_box_odds')->insert([
                    'box_id' => $boxId,
                    'product_id' => $product->id,
                    'weight_bps' => $weights[$index],
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS blind_pulls, blind_box_odds, blind_boxes, auction_bids, auctions,
              closet_items, try_on_votes, try_on_polls, try_on_renders, prize_spins, ritual_missions,
              ritual_checkins, gifts;
            SQL);
    }
};

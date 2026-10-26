<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Observed prices, shop region, store trust, coupons, and a refreshed FX table.
 * Purchase tables are left as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->char('shop_country', 2)->nullable();
            $table->char('shop_currency', 3)->nullable();
        });

        Schema::table('wishlists', function (Blueprint $table): void {
            $table->char('country', 2)->nullable();
            $table->unsignedInteger('target_minor')->nullable();
        });

        DB::unprepared(<<<'SQL'
            CREATE TABLE price_observations (
              id             uuid PRIMARY KEY,
              product_key    text NOT NULL CHECK (char_length(product_key) = 64),
              query          text NOT NULL CHECK (char_length(query) BETWEEN 1 AND 120),
              store          text NOT NULL CHECK (char_length(store) BETWEEN 1 AND 120),
              host           text NOT NULL CHECK (char_length(host) BETWEEN 3 AND 190),
              currency       char(3) NOT NULL,
              price_minor    integer NOT NULL CHECK (price_minor >= 0),
              shipping_minor integer NULL CHECK (shipping_minor IS NULL OR shipping_minor >= 0),
              tax_minor      integer NULL CHECK (tax_minor IS NULL OR tax_minor >= 0),
              total_minor    integer NOT NULL CHECK (total_minor >= 0),
              observed_on    date NOT NULL,
              created_at     timestamptz NOT NULL DEFAULT now()
            );
            CREATE UNIQUE INDEX price_observations_day
              ON price_observations (product_key, host, currency, observed_on);
            CREATE INDEX price_observations_recent
              ON price_observations (product_key, observed_on DESC);

            CREATE TABLE compare_searches (
              id               uuid PRIMARY KEY,
              query            text NOT NULL CHECK (char_length(query) BETWEEN 1 AND 120),
              country          char(2) NOT NULL,
              currency         char(3) NOT NULL,
              last_searched_at timestamptz NOT NULL
            );
            CREATE UNIQUE INDEX compare_searches_identity
              ON compare_searches (lower(query), country, currency);

            CREATE TABLE store_reputations (
              host          text PRIMARY KEY CHECK (char_length(host) BETWEEN 3 AND 190),
              rating        numeric(3, 2) NULL CHECK (rating IS NULL OR (rating >= 0 AND rating <= 5)),
              review_count  integer NULL CHECK (review_count IS NULL OR review_count >= 0),
              status        text NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'flagged', 'blocked')),
              note          text NULL CHECK (note IS NULL OR char_length(note) <= 200),
              updated_at    timestamptz NOT NULL DEFAULT now()
            );

            CREATE TABLE store_coupons (
              id         uuid PRIMARY KEY,
              host       text NOT NULL CHECK (char_length(host) BETWEEN 3 AND 190),
              code       text NOT NULL CHECK (char_length(code) BETWEEN 2 AND 40),
              label      text NOT NULL CHECK (char_length(label) BETWEEN 1 AND 120),
              country    char(2) NULL,
              active     boolean NOT NULL DEFAULT true,
              created_at timestamptz NOT NULL DEFAULT now()
            );
            CREATE INDEX store_coupons_host ON store_coupons (host) WHERE active;

            CREATE TABLE fx_rates (
              currency   char(3) PRIMARY KEY,
              per_usd    integer NOT NULL CHECK (per_usd > 0),
              fetched_at timestamptz NOT NULL
            );
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('fx_rates');
        Schema::dropIfExists('store_coupons');
        Schema::dropIfExists('store_reputations');
        Schema::dropIfExists('compare_searches');
        Schema::dropIfExists('price_observations');
        Schema::table('wishlists', function (Blueprint $table): void {
            $table->dropColumn(['country', 'target_minor']);
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['shop_country', 'shop_currency']);
        });
    }
};

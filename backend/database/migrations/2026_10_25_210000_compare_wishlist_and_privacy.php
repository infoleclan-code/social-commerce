<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Price-comparison clicks and wishlists, admin TOTP replay state, and the removal
 * of leftover gift address columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedInteger('totp_last_step')->nullable();
        });

        DB::unprepared(<<<'SQL'
            CREATE TABLE outbound_clicks (
              id         uuid PRIMARY KEY,
              user_id    uuid NOT NULL REFERENCES users (id),
              store      text NOT NULL CHECK (char_length(store) BETWEEN 1 AND 120),
              host       text NOT NULL CHECK (char_length(host) BETWEEN 1 AND 190),
              offer_key  text NOT NULL CHECK (char_length(offer_key) BETWEEN 8 AND 80),
              created_at timestamptz NOT NULL DEFAULT now()
            );
            CREATE INDEX outbound_clicks_user_created ON outbound_clicks (user_id, created_at DESC);

            CREATE TABLE wishlists (
              id               uuid PRIMARY KEY,
              user_id          uuid NOT NULL REFERENCES users (id),
              query            text NOT NULL CHECK (char_length(query) BETWEEN 1 AND 120),
              brand            text NULL CHECK (brand IS NULL OR char_length(brand) BETWEEN 1 AND 80),
              size             text NULL CHECK (size IS NULL OR char_length(size) BETWEEN 1 AND 40),
              currency         char(3) NOT NULL,
              best_total_minor integer NULL CHECK (best_total_minor IS NULL OR best_total_minor >= 0),
              notify           boolean NOT NULL DEFAULT true,
              created_at       timestamptz NOT NULL DEFAULT now(),
              updated_at       timestamptz NOT NULL DEFAULT now()
            );
            CREATE UNIQUE INDEX wishlists_identity
              ON wishlists (user_id, lower(query), coalesce(lower(brand), ''), coalesce(lower(size), ''), currency);
        SQL);

        if (Schema::hasColumn('gifts', 'address_line')) {
            foreach (DB::table('gifts')->whereNotNull('address_line')->orderBy('id')->get() as $gift) {
                if (is_string($gift->address_ciphertext ?? null) && $gift->address_ciphertext !== '') {
                    continue;
                }
                DB::table('gifts')->where('id', $gift->id)->update([
                    'address_ciphertext' => Crypt::encryptString(json_encode([
                        'line' => (string) $gift->address_line,
                        'locality' => (string) ($gift->locality ?? ''),
                        'postal_code' => (string) ($gift->postal_code ?? ''),
                        'country' => (string) ($gift->country ?? ''),
                    ], JSON_THROW_ON_ERROR)),
                ]);
            }
            DB::statement('ALTER TABLE gifts DROP COLUMN address_line, DROP COLUMN locality, DROP COLUMN postal_code, DROP COLUMN country');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wishlists');
        Schema::dropIfExists('outbound_clicks');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('totp_last_step');
        });
    }
};

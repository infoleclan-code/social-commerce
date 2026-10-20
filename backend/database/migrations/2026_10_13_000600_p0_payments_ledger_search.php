<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * P0: ledger-backed cash, delivery-based withdrawal, saved Stripe methods,
 * image embeddings, age assurance, and private-by-default purchase sharing.
 * Card numbers are not columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE users
              ADD COLUMN stripe_customer_id text UNIQUE,
              ADD COLUMN age_assured_at timestamptz;

            ALTER TABLE orders
              ADD COLUMN stripe_payment_intent_id text,
              ADD COLUMN delivered_at timestamptz,
              ADD COLUMN share_publicly boolean NOT NULL DEFAULT false;

            CREATE TABLE saved_payment_methods (
              id                         uuid PRIMARY KEY DEFAULT gen_uuid_v7(),
              user_id                    uuid NOT NULL REFERENCES users (id) ON DELETE CASCADE,
              stripe_payment_method_id   text NOT NULL UNIQUE,
              brand                      text NOT NULL,
              last4                      char(4) NOT NULL CHECK (last4 ~ '^[0-9]{4}$'),
              exp_month                  integer,
              exp_year                   integer,
              is_default                 boolean NOT NULL DEFAULT false,
              created_at                 timestamptz NOT NULL DEFAULT now()
            );

            CREATE TABLE ledger_journals (
              id          uuid PRIMARY KEY DEFAULT gen_uuid_v7(),
              kind        text NOT NULL CHECK (kind IN (
                            'sale', 'commission_pending', 'commission_release', 'commission_reverse', 'refund'
                          )),
              order_id    uuid,
              created_at  timestamptz NOT NULL DEFAULT now()
            );
            CREATE TRIGGER ledger_journals_append_only
              BEFORE UPDATE OR DELETE ON ledger_journals
              FOR EACH ROW EXECUTE FUNCTION forbid_mutation();

            CREATE TABLE ledger_lines (
              id            uuid PRIMARY KEY DEFAULT gen_uuid_v7(),
              journal_id    uuid NOT NULL REFERENCES ledger_journals (id),
              account       text NOT NULL,
              direction     text NOT NULL CHECK (direction IN ('debit', 'credit')),
              amount_minor  bigint NOT NULL CHECK (amount_minor > 0),
              currency      char(3) NOT NULL,
              created_at    timestamptz NOT NULL DEFAULT now()
            );
            CREATE TRIGGER ledger_lines_append_only
              BEFORE UPDATE OR DELETE ON ledger_lines
              FOR EACH ROW EXECUTE FUNCTION forbid_mutation();
            CREATE INDEX ledger_lines_journal_idx ON ledger_lines (journal_id);
            CREATE INDEX ledger_lines_account_idx ON ledger_lines (account);

            CREATE OR REPLACE FUNCTION ledger_journal_must_balance() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
              debit bigint;
              credit bigint;
            BEGIN
              SELECT COALESCE(SUM(amount_minor) FILTER (WHERE direction = 'debit'), 0),
                     COALESCE(SUM(amount_minor) FILTER (WHERE direction = 'credit'), 0)
                INTO debit, credit
                FROM ledger_lines
               WHERE journal_id = NEW.journal_id;
              IF debit <> credit THEN
                RAISE EXCEPTION 'ledger journal % is not balanced (% debit, % credit)', NEW.journal_id, debit, credit
                  USING ERRCODE = 'restrict_violation';
              END IF;
              RETURN NULL;
            END $$;

            CREATE CONSTRAINT TRIGGER ledger_lines_balanced
              AFTER INSERT ON ledger_lines
              DEFERRABLE INITIALLY DEFERRED
              FOR EACH ROW EXECUTE FUNCTION ledger_journal_must_balance();

            CREATE OR REPLACE FUNCTION user_wallets_protect_cash() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              IF NEW.cash_minor IS DISTINCT FROM OLD.cash_minor
                 AND current_setting('app.ledger_posting', true) IS DISTINCT FROM '1' THEN
                RAISE EXCEPTION 'user_wallets.cash_minor is posted only by the ledger'
                  USING ERRCODE = 'restrict_violation';
              END IF;
              RETURN NEW;
            END $$;

            DROP TRIGGER IF EXISTS user_wallets_protect_cash ON user_wallets;
            CREATE TRIGGER user_wallets_protect_cash
              BEFORE UPDATE OF cash_minor ON user_wallets
              FOR EACH ROW EXECUTE FUNCTION user_wallets_protect_cash();

            CREATE OR REPLACE FUNCTION embedding_cosine(a float8[], b float8[]) RETURNS float8
            LANGUAGE sql IMMUTABLE AS $$
              SELECT CASE WHEN denom = 0 THEN 0 ELSE dot / denom END
              FROM (
                SELECT COALESCE(SUM(x * y), 0) AS dot,
                       sqrt(COALESCE(SUM(x * x), 0)) * sqrt(COALESCE(SUM(y * y), 0)) AS denom
                FROM unnest(a, b) AS t(x, y)
              ) s;
            $$;

            ALTER TABLE products
              ADD COLUMN embedding float8[] CHECK (embedding IS NULL OR cardinality(embedding) = 32);
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE products DROP COLUMN IF EXISTS embedding;
            DROP FUNCTION IF EXISTS embedding_cosine(float8[], float8[]);
            DROP TRIGGER IF EXISTS user_wallets_protect_cash ON user_wallets;
            DROP FUNCTION IF EXISTS user_wallets_protect_cash();
            DROP TABLE IF EXISTS ledger_lines, ledger_journals, saved_payment_methods;
            ALTER TABLE orders DROP COLUMN IF EXISTS stripe_payment_intent_id, DROP COLUMN IF EXISTS delivered_at, DROP COLUMN IF EXISTS share_publicly;
            ALTER TABLE users DROP COLUMN IF EXISTS stripe_customer_id, DROP COLUMN IF EXISTS age_assured_at;
            SQL);
    }
};

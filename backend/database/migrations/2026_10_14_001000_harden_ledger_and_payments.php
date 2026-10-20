<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Hardening pass: money can only move through a balanced, idempotent, per-currency ledger, and an
 * order can only be paid after a real card capture.
 *
 * Ledger
 *  - every journal carries a unique idempotency key, so a retried or concurrent posting is applied once;
 *  - journals must balance per currency (100 USD against 100 EUR no longer passes) and have two lines;
 *  - ledger_balances is maintained by trigger in the same transaction as the lines (O(1) reads, no
 *    read-then-write race); user:{id}:available may go below zero only through a reversal, and
 *    user:{id}:pending never does;
 *  - user_wallets.cash_minor mirrors the available balance from that trigger, never from application code.
 *
 * Payments
 *  - orders gain payment_status; status 'paid' requires a captured payment (CHECK, NOT VALID for
 *    legacy rows), status changes follow status_transitions;
 *  - payment_authorizations gain the states of a real PaymentIntent (pending, requires_action, failed);
 *  - a PaymentIntent maps to at most one order (unique index, also used by webhooks);
 *  - idempotency_keys backs the Idempotency-Key middleware; payment_anomalies records webhook data
 *    that does not match our records.
 *
 * Indexes on existing (large) tables are built CONCURRENTLY in the next migration, 001200, so this one
 * only takes short locks.
 *
 * Abuse guards
 *  - a squad cannot exceed max_members under concurrency (trigger with a row lock);
 *  - product_price_history records how long each price held, so price protection pays only for prices
 *    that actually stayed on sale;
 *  - listing_flags holds catalog listings that look like copies of another seller's listing.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- ------------------------------------------------------------------ ledger
            ALTER TABLE ledger_journals ADD COLUMN idempotency_key text;
            ALTER TABLE ledger_journals DISABLE TRIGGER ledger_journals_append_only;
            UPDATE ledger_journals SET idempotency_key = 'legacy:' || id::text WHERE idempotency_key IS NULL;
            ALTER TABLE ledger_journals ENABLE TRIGGER ledger_journals_append_only;
            ALTER TABLE ledger_journals
              ALTER COLUMN idempotency_key SET NOT NULL,
              ADD CONSTRAINT ledger_journals_idempotency_key_key UNIQUE (idempotency_key),
              ADD CONSTRAINT ledger_journals_idempotency_key_check CHECK (char_length(idempotency_key) BETWEEN 8 AND 200);

            ALTER TABLE ledger_journals DROP CONSTRAINT IF EXISTS ledger_journals_kind_check;
            ALTER TABLE ledger_journals ADD CONSTRAINT ledger_journals_kind_check CHECK (kind IN (
              'sale', 'commission_pending', 'commission_release', 'commission_reverse', 'refund',
              'price_protection', 'price_protection_reverse'
            ));

            ALTER TABLE ledger_lines
              ADD CONSTRAINT ledger_lines_account_check CHECK (
                account ~ '^(platform:[a-z0-9_-]{2,60}|user:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}:(pending|available))$'
              ) NOT VALID,
              ADD CONSTRAINT ledger_lines_currency_check CHECK (currency ~ '^[A-Z]{3}$') NOT VALID,
              ADD CONSTRAINT ledger_lines_amount_cap CHECK (amount_minor <= 1000000000000) NOT VALID;

            CREATE OR REPLACE FUNCTION ledger_journal_must_balance() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
              line_count integer;
              unbalanced record;
            BEGIN
              SELECT count(*) INTO line_count FROM ledger_lines WHERE journal_id = NEW.journal_id;
              IF line_count < 2 THEN
                RAISE EXCEPTION 'ledger journal % is not balanced (it needs at least two lines)', NEW.journal_id
                  USING ERRCODE = 'restrict_violation';
              END IF;
              SELECT currency,
                     COALESCE(SUM(amount_minor) FILTER (WHERE direction = 'debit'), 0) AS debit,
                     COALESCE(SUM(amount_minor) FILTER (WHERE direction = 'credit'), 0) AS credit
                INTO unbalanced
                FROM ledger_lines
               WHERE journal_id = NEW.journal_id
               GROUP BY currency
              HAVING COALESCE(SUM(amount_minor) FILTER (WHERE direction = 'debit'), 0)
                  <> COALESCE(SUM(amount_minor) FILTER (WHERE direction = 'credit'), 0)
               LIMIT 1;
              IF FOUND THEN
                RAISE EXCEPTION 'ledger journal % is not balanced in % (% debit, % credit)',
                  NEW.journal_id, unbalanced.currency, unbalanced.debit, unbalanced.credit
                  USING ERRCODE = 'restrict_violation';
              END IF;
              RETURN NULL;
            END $$;

            -- Running balances. A user account is one row (shard 0), so its floor checks see one number.
            -- A platform account is spread over 16 shards: every order touches platform:commission, and a
            -- single row would serialize all checkouts on one row lock. Balance = SUM over shards.
            CREATE TABLE ledger_balances (
              account        text NOT NULL,
              currency       char(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
              shard          smallint NOT NULL DEFAULT 0 CHECK (shard BETWEEN 0 AND 15),
              balance_minor  bigint NOT NULL DEFAULT 0,
              updated_at     timestamptz NOT NULL DEFAULT now(),
              PRIMARY KEY (account, currency, shard),
              CHECK (account LIKE 'platform:%' OR shard = 0)
            );
            INSERT INTO ledger_balances (account, currency, shard, balance_minor, updated_at)
            SELECT account, currency, 0,
                   SUM(CASE WHEN direction = 'credit' THEN amount_minor ELSE -amount_minor END),
                   now()
              FROM ledger_lines
             GROUP BY account, currency;

            CREATE OR REPLACE FUNCTION ledger_lines_apply_balance() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
              delta         bigint;
              new_balance   bigint;
              journal_kind  text;
              owner         uuid;
              target_shard  smallint := 0;
            BEGIN
              PERFORM set_config('app.ledger_posting', '1', true);
              delta := CASE WHEN NEW.direction = 'credit' THEN NEW.amount_minor ELSE -NEW.amount_minor END;
              IF NEW.account LIKE 'platform:%' THEN
                target_shard := (hashtext(NEW.journal_id::text) & 15)::smallint;
              END IF;
              INSERT INTO ledger_balances AS b (account, currency, shard, balance_minor, updated_at)
              VALUES (NEW.account, NEW.currency, target_shard, delta, now())
              ON CONFLICT (account, currency, shard) DO UPDATE
                SET balance_minor = b.balance_minor + EXCLUDED.balance_minor, updated_at = now()
              RETURNING b.balance_minor INTO new_balance;

              IF NEW.account LIKE 'user:%:pending' AND new_balance < 0 THEN
                RAISE EXCEPTION 'pending balance of % cannot go below zero', NEW.account
                  USING ERRCODE = 'check_violation';
              END IF;

              IF NEW.account LIKE 'user:%:available' THEN
                IF new_balance < 0 THEN
                  SELECT kind INTO journal_kind FROM ledger_journals WHERE id = NEW.journal_id;
                  IF journal_kind NOT IN ('commission_reverse', 'price_protection_reverse', 'refund') THEN
                    RAISE EXCEPTION 'insufficient available balance on %', NEW.account
                      USING ERRCODE = 'check_violation';
                  END IF;
                END IF;
                owner := substring(NEW.account FROM 6 FOR 36)::uuid;
                PERFORM set_config('app.ledger_posting', '1', true);
                INSERT INTO user_wallets AS w (user_id, coins, xp, cash_minor, currency, updated_at)
                VALUES (owner, 0, 0, GREATEST(new_balance, 0), NEW.currency, now())
                ON CONFLICT (user_id) DO UPDATE
                  SET cash_minor = EXCLUDED.cash_minor, updated_at = now()
                  WHERE w.currency = EXCLUDED.currency;
              END IF;

              PERFORM set_config('app.ledger_posting', '', true);
              RETURN NEW;
            END $$;

            CREATE TRIGGER ledger_lines_apply_balance
              AFTER INSERT ON ledger_lines
              FOR EACH ROW EXECUTE FUNCTION ledger_lines_apply_balance();

            -- Balances are derived data: only a ledger posting (the trigger above) may write them.
            CREATE OR REPLACE FUNCTION ledger_balances_protect() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              IF current_setting('app.ledger_posting', true) IS DISTINCT FROM '1' THEN
                RAISE EXCEPTION 'ledger_balances is written only by ledger postings'
                  USING ERRCODE = 'restrict_violation';
              END IF;
              RETURN COALESCE(NEW, OLD);
            END $$;
            CREATE TRIGGER ledger_balances_protect
              BEFORE INSERT OR UPDATE OR DELETE ON ledger_balances
              FOR EACH ROW EXECUTE FUNCTION ledger_balances_protect();

            -- A wallet row could previously be INSERTed with cash; only the ledger trigger may set it.
            CREATE OR REPLACE FUNCTION user_wallets_protect_cash() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              IF current_setting('app.ledger_posting', true) IS DISTINCT FROM '1' THEN
                IF TG_OP = 'INSERT' AND NEW.cash_minor <> 0 THEN
                  RAISE EXCEPTION 'user_wallets.cash_minor is posted only by the ledger'
                    USING ERRCODE = 'restrict_violation';
                END IF;
                IF TG_OP = 'UPDATE' AND NEW.cash_minor IS DISTINCT FROM OLD.cash_minor THEN
                  RAISE EXCEPTION 'user_wallets.cash_minor is posted only by the ledger'
                    USING ERRCODE = 'restrict_violation';
                END IF;
              END IF;
              RETURN NEW;
            END $$;
            DROP TRIGGER IF EXISTS user_wallets_protect_cash ON user_wallets;
            CREATE TRIGGER user_wallets_protect_cash
              BEFORE INSERT OR UPDATE OF cash_minor ON user_wallets
              FOR EACH ROW EXECUTE FUNCTION user_wallets_protect_cash();

            -- ------------------------------------------------------------------ orders and payments
            ALTER TABLE orders
              ADD COLUMN payment_status text NOT NULL DEFAULT 'unpaid' CHECK (payment_status IN (
                'unpaid', 'pending', 'requires_action', 'authorized', 'captured',
                'partially_refunded', 'refunded', 'voided', 'failed', 'disputed'
              )),
              ADD COLUMN purpose text NOT NULL DEFAULT 'checkout' CHECK (purpose IN (
                'checkout', 'one_tap', 'squad', 'gift', 'blind_box', 'legacy'
              )),
              ADD COLUMN amount_refunded_minor bigint NOT NULL DEFAULT 0 CHECK (amount_refunded_minor >= 0),
              ADD COLUMN creator_commission_minor bigint NOT NULL DEFAULT 0 CHECK (creator_commission_minor >= 0),
              ADD COLUMN referral_link_id uuid REFERENCES referral_links (id),
              ADD COLUMN request_fingerprint char(64),
              ADD COLUMN captured_at timestamptz,
              ADD COLUMN cancelled_at timestamptz;

            UPDATE orders SET payment_status = 'captured', captured_at = created_at
             WHERE status = 'paid' AND stripe_payment_intent_id IS NOT NULL;
            UPDATE orders SET payment_status = 'authorized'
             WHERE status = 'processing' AND stripe_payment_intent_id IS NOT NULL;
            UPDATE orders SET purpose = 'legacy'
             WHERE stripe_payment_intent_id IS NULL AND status IN ('paid', 'processing');

            ALTER TABLE orders
              ALTER COLUMN status SET DEFAULT 'processing',
              ADD CONSTRAINT orders_refund_within_total CHECK (amount_refunded_minor <= total_minor),
              ADD CONSTRAINT orders_paid_means_captured CHECK (
                status <> 'paid'
                OR payment_status IN ('captured', 'partially_refunded', 'disputed')
                OR purpose = 'legacy'
              );

            -- 'legacy' marks rows written before real payments existed; nothing new may use it.
            CREATE OR REPLACE FUNCTION orders_forbid_legacy() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              IF NEW.purpose = 'legacy' AND (TG_OP = 'INSERT' OR OLD.purpose IS DISTINCT FROM 'legacy') THEN
                RAISE EXCEPTION 'orders.purpose legacy is reserved for rows that predate payments'
                  USING ERRCODE = 'check_violation';
              END IF;
              RETURN NEW;
            END $$;
            CREATE TRIGGER orders_forbid_legacy
              BEFORE INSERT OR UPDATE OF purpose ON orders
              FOR EACH ROW EXECUTE FUNCTION orders_forbid_legacy();

            -- payment_status only moves forward. A webhook that arrives late, twice, or out of order
            -- cannot drag a captured payment back to authorized.
            CREATE OR REPLACE FUNCTION orders_payment_status_transition() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
              IF NEW.payment_status IS NOT DISTINCT FROM OLD.payment_status THEN
                RETURN NEW;
              END IF;
              IF (OLD.payment_status, NEW.payment_status) IN (
                ('unpaid', 'pending'),
                ('pending', 'requires_action'), ('pending', 'authorized'), ('pending', 'captured'),
                ('pending', 'failed'), ('pending', 'voided'),
                ('requires_action', 'authorized'), ('requires_action', 'captured'),
                ('requires_action', 'failed'), ('requires_action', 'voided'),
                ('authorized', 'captured'), ('authorized', 'voided'), ('authorized', 'failed'),
                ('captured', 'partially_refunded'), ('captured', 'refunded'), ('captured', 'disputed'),
                ('partially_refunded', 'refunded'), ('partially_refunded', 'disputed'),
                ('disputed', 'captured'), ('disputed', 'partially_refunded'), ('disputed', 'refunded')
              ) THEN
                RETURN NEW;
              END IF;
              RAISE EXCEPTION 'Illegal orders payment_status transition: % -> %', OLD.payment_status, NEW.payment_status
                USING ERRCODE = 'check_violation';
            END $$;
            CREATE TRIGGER orders_payment_status_transition
              BEFORE UPDATE OF payment_status ON orders
              FOR EACH ROW EXECUTE FUNCTION orders_payment_status_transition();


            INSERT INTO status_transitions (table_name, from_status, to_status) VALUES
              ('orders', 'processing', 'paid'),
              ('orders', 'processing', 'cancelled'),
              ('orders', 'paid', 'cancelled')
            ON CONFLICT DO NOTHING;
            CREATE TRIGGER orders_status_transition
              BEFORE UPDATE OF status ON orders
              FOR EACH ROW EXECUTE FUNCTION enforce_status_transition();

            ALTER TABLE payment_authorizations DROP CONSTRAINT IF EXISTS payment_authorizations_status_check;
            ALTER TABLE payment_authorizations
              ADD CONSTRAINT payment_authorizations_status_check CHECK (status IN (
                'pending', 'requires_action', 'authorized', 'captured', 'voided', 'failed'
              )),
              ADD COLUMN stripe_payment_intent_id text,
              ADD COLUMN stripe_payment_method_id text CHECK (stripe_payment_method_id IS NULL OR stripe_payment_method_id ~ '^pm_[A-Za-z0-9_]{1,100}$'),
              ADD COLUMN attempts integer NOT NULL DEFAULT 0 CHECK (attempts >= 0),
              ADD COLUMN next_attempt_at timestamptz,
              ADD COLUMN last_error text CHECK (last_error IS NULL OR char_length(last_error) <= 300),
              ADD COLUMN capture_after timestamptz,
              ADD COLUMN captured_at timestamptz;
            ALTER TABLE payment_authorizations DROP CONSTRAINT IF EXISTS payment_authorizations_purpose_check;
            ALTER TABLE payment_authorizations ADD CONSTRAINT payment_authorizations_purpose_check
              CHECK (purpose IN ('squad', 'one_tap', 'drop', 'checkout', 'gift', 'blind_box'));
            UPDATE payment_authorizations a SET stripe_payment_intent_id = o.stripe_payment_intent_id,
                   capture_after = a.expires_at
              FROM orders o WHERE o.id = a.order_id;

            INSERT INTO status_transitions (table_name, from_status, to_status) VALUES
              ('payment_authorizations', 'pending', 'requires_action'),
              ('payment_authorizations', 'pending', 'authorized'),
              ('payment_authorizations', 'pending', 'captured'),
              ('payment_authorizations', 'pending', 'failed'),
              ('payment_authorizations', 'pending', 'voided'),
              ('payment_authorizations', 'requires_action', 'authorized'),
              ('payment_authorizations', 'requires_action', 'captured'),
              ('payment_authorizations', 'requires_action', 'failed'),
              ('payment_authorizations', 'requires_action', 'voided'),
              ('payment_authorizations', 'authorized', 'captured'),
              ('payment_authorizations', 'authorized', 'voided'),
              ('payment_authorizations', 'authorized', 'failed')
            ON CONFLICT DO NOTHING;
            CREATE TRIGGER payment_authorizations_status_transition
              BEFORE UPDATE OF status ON payment_authorizations
              FOR EACH ROW EXECUTE FUNCTION enforce_status_transition();

            ALTER TABLE stripe_events
              ADD COLUMN livemode boolean,
              ADD COLUMN outcome text NOT NULL DEFAULT 'applied' CHECK (outcome IN ('applied', 'ignored', 'anomaly'));

            CREATE TABLE payment_anomalies (
              id              uuid PRIMARY KEY DEFAULT gen_uuid_v7(),
              order_id        uuid REFERENCES orders (id),
              stripe_event_id text,
              kind            text NOT NULL CHECK (kind IN (
                                'amount_mismatch', 'currency_mismatch', 'order_mismatch', 'livemode_mismatch',
                                'unknown_intent', 'late_capture', 'capture_failed'
                              )),
              detail          jsonb NOT NULL DEFAULT '{}'::jsonb,
              created_at      timestamptz NOT NULL DEFAULT now()
            );
            CREATE INDEX payment_anomalies_recent_idx ON payment_anomalies (created_at DESC);

            CREATE TABLE payment_disputes (
              id            text PRIMARY KEY CHECK (id ~ '^dp_[A-Za-z0-9_]{1,100}$'),
              order_id      uuid NOT NULL REFERENCES orders (id),
              status        text NOT NULL CHECK (status IN ('open', 'won', 'lost')),
              amount_minor  bigint NOT NULL CHECK (amount_minor >= 0),
              currency      char(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
              reason        text CHECK (reason IS NULL OR char_length(reason) <= 60),
              opened_at     timestamptz NOT NULL DEFAULT now(),
              closed_at     timestamptz,
              CHECK ((status = 'open') = (closed_at IS NULL))
            );
            CREATE INDEX payment_disputes_order_idx ON payment_disputes (order_id);
            INSERT INTO status_transitions (table_name, from_status, to_status) VALUES
              ('payment_disputes', 'open', 'won'),
              ('payment_disputes', 'open', 'lost')
            ON CONFLICT DO NOTHING;
            CREATE TRIGGER payment_disputes_status_transition
              BEFORE UPDATE OF status ON payment_disputes
              FOR EACH ROW EXECUTE FUNCTION enforce_status_transition();

            CREATE TABLE idempotency_keys (
              user_id          uuid NOT NULL REFERENCES users (id) ON DELETE CASCADE,
              key              text NOT NULL CHECK (key ~ '^[A-Za-z0-9_-]{8,80}$'),
              route            text NOT NULL CHECK (char_length(route) <= 120),
              fingerprint      char(64) NOT NULL,
              status           text NOT NULL CHECK (status IN ('in_progress', 'completed')),
              response_status  smallint,
              response_body    jsonb,
              locked_until     timestamptz NOT NULL,
              created_at       timestamptz NOT NULL DEFAULT now(),
              PRIMARY KEY (user_id, key)
            );
            CREATE INDEX idempotency_keys_created_idx ON idempotency_keys (created_at);

            -- ------------------------------------------------------------------ purchases built on orders
            ALTER TABLE gifts DROP CONSTRAINT IF EXISTS gifts_status_check;
            ALTER TABLE gifts DROP CONSTRAINT IF EXISTS gifts_check;
            ALTER TABLE gifts
              ALTER COLUMN status SET DEFAULT 'pending',
              ADD CONSTRAINT gifts_status_check CHECK (status IN ('pending', 'paid', 'claimed', 'cancelled')),
              ADD CONSTRAINT gifts_claim_consistent CHECK (
                (status IN ('pending', 'paid') AND claimed_by IS NULL)
                OR (status = 'claimed' AND claimed_by IS NOT NULL)
                OR status = 'cancelled'
              ) NOT VALID;
            INSERT INTO status_transitions (table_name, from_status, to_status) VALUES
              ('gifts', 'pending', 'paid'),
              ('gifts', 'pending', 'cancelled'),
              ('gifts', 'paid', 'claimed'),
              ('gifts', 'paid', 'cancelled'),
              ('gifts', 'claimed', 'cancelled')
            ON CONFLICT DO NOTHING;
            CREATE TRIGGER gifts_status_transition
              BEFORE UPDATE OF status ON gifts
              FOR EACH ROW EXECUTE FUNCTION enforce_status_transition();

            -- One blind-box pull per user and day still holds, but a pull whose payment failed no longer
            -- burns the day: the unique key ignores failed pulls.
            ALTER TABLE blind_pulls
              ADD COLUMN order_id uuid REFERENCES orders (id),
              ADD COLUMN status text NOT NULL DEFAULT 'paid' CHECK (status IN ('pending', 'paid', 'failed'));
            INSERT INTO status_transitions (table_name, from_status, to_status) VALUES
              ('blind_pulls', 'pending', 'paid'),
              ('blind_pulls', 'pending', 'failed')
            ON CONFLICT DO NOTHING;
            CREATE TRIGGER blind_pulls_status_transition
              BEFORE UPDATE OF status ON blind_pulls
              FOR EACH ROW EXECUTE FUNCTION enforce_status_transition();

            CREATE OR REPLACE FUNCTION squad_members_capacity() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
              capacity integer;
              members  integer;
            BEGIN
              SELECT max_members INTO capacity FROM squads WHERE id = NEW.squad_id FOR UPDATE;
              IF EXISTS (SELECT 1 FROM squad_members WHERE squad_id = NEW.squad_id AND user_id = NEW.user_id) THEN
                RETURN NEW;  -- a re-join; ON CONFLICT DO NOTHING turns it into a no-op
              END IF;
              SELECT count(*) INTO members FROM squad_members WHERE squad_id = NEW.squad_id;
              IF members >= capacity THEN
                RAISE EXCEPTION 'squad % is full', NEW.squad_id USING ERRCODE = 'check_violation';
              END IF;
              RETURN NEW;
            END $$;
            CREATE TRIGGER squad_members_capacity
              BEFORE INSERT ON squad_members
              FOR EACH ROW EXECUTE FUNCTION squad_members_capacity();


            -- ------------------------------------------------------------------ catalog
            ALTER TABLE products
              ADD COLUMN canonical_key text CHECK (canonical_key IS NULL OR char_length(canonical_key) BETWEEN 3 AND 200),
              ADD COLUMN listing_status text NOT NULL DEFAULT 'active' CHECK (listing_status IN ('active', 'review', 'blocked')),
              ADD COLUMN image_sha256 char(64),
              ADD CONSTRAINT products_image_url_https CHECK (image_url ~ '^https://[^\s@]+$') NOT VALID;

            CREATE TABLE product_price_history (
              product_id   uuid NOT NULL REFERENCES products (id) ON DELETE CASCADE,
              price_minor  bigint NOT NULL CHECK (price_minor >= 0),
              currency     char(3) NOT NULL,
              valid_from   timestamptz NOT NULL,
              valid_to     timestamptz,
              PRIMARY KEY (product_id, valid_from),
              CHECK (valid_to IS NULL OR valid_to >= valid_from)
            );
            CREATE UNIQUE INDEX product_price_history_current_uidx ON product_price_history (product_id)
              WHERE valid_to IS NULL;
            INSERT INTO product_price_history (product_id, price_minor, currency, valid_from)
            SELECT id, price_minor, currency, created_at FROM products;

            CREATE OR REPLACE FUNCTION products_track_price() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
              changed_at timestamptz := clock_timestamp();
            BEGIN
              IF TG_OP = 'UPDATE'
                 AND NEW.price_minor IS NOT DISTINCT FROM OLD.price_minor
                 AND NEW.currency IS NOT DISTINCT FROM OLD.currency THEN
                RETURN NEW;
              END IF;
              UPDATE product_price_history SET valid_to = changed_at
               WHERE product_id = NEW.id AND valid_to IS NULL;
              INSERT INTO product_price_history (product_id, price_minor, currency, valid_from)
              VALUES (NEW.id, NEW.price_minor, NEW.currency, changed_at)
              ON CONFLICT (product_id, valid_from) DO NOTHING;
              RETURN NEW;
            END $$;
            CREATE TRIGGER products_track_price
              AFTER INSERT OR UPDATE OF price_minor, currency ON products
              FOR EACH ROW EXECUTE FUNCTION products_track_price();

            CREATE TABLE listing_flags (
              id                  uuid PRIMARY KEY DEFAULT gen_uuid_v7(),
              product_id          uuid NOT NULL REFERENCES products (id) ON DELETE CASCADE,
              related_product_id  uuid REFERENCES products (id) ON DELETE CASCADE,
              kind                text NOT NULL CHECK (kind IN ('duplicate_image', 'image_unreachable', 'image_rejected')),
              score               double precision CHECK (score IS NULL OR (score >= 0 AND score <= 1)),
              status              text NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'dismissed', 'confirmed')),
              created_at          timestamptz NOT NULL DEFAULT now(),
              UNIQUE NULLS NOT DISTINCT (product_id, kind, related_product_id)
            );
            CREATE INDEX listing_flags_open_idx ON listing_flags (created_at DESC) WHERE status = 'open';

            ALTER TABLE price_protections
              ADD COLUMN last_checked_at timestamptz;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE price_protections DROP COLUMN IF EXISTS last_checked_at;
            DROP INDEX IF EXISTS listing_flags_open_idx;
            DROP TABLE IF EXISTS listing_flags;
            DROP TRIGGER IF EXISTS products_track_price ON products;
            DROP FUNCTION IF EXISTS products_track_price();
            DROP TABLE IF EXISTS product_price_history;
            ALTER TABLE products
              DROP CONSTRAINT IF EXISTS products_image_url_https,
              DROP COLUMN IF EXISTS image_sha256,
              DROP COLUMN IF EXISTS listing_status,
              DROP COLUMN IF EXISTS canonical_key;

            DROP TRIGGER IF EXISTS squad_members_capacity ON squad_members;
            DROP FUNCTION IF EXISTS squad_members_capacity();
            DROP TRIGGER IF EXISTS blind_pulls_status_transition ON blind_pulls;
            ALTER TABLE blind_pulls DROP COLUMN IF EXISTS status, DROP COLUMN IF EXISTS order_id;
            DROP TRIGGER IF EXISTS gifts_status_transition ON gifts;
            ALTER TABLE gifts DROP CONSTRAINT IF EXISTS gifts_claim_consistent, DROP CONSTRAINT IF EXISTS gifts_status_check;
            ALTER TABLE gifts ALTER COLUMN status DROP DEFAULT;
            ALTER TABLE gifts
              ADD CONSTRAINT gifts_status_check CHECK (status IN ('paid', 'claimed')) NOT VALID,
              ADD CONSTRAINT gifts_check CHECK (status = 'paid' OR claimed_by IS NOT NULL) NOT VALID;

            DROP TABLE IF EXISTS payment_disputes, idempotency_keys, payment_anomalies;
            ALTER TABLE stripe_events DROP COLUMN IF EXISTS outcome, DROP COLUMN IF EXISTS livemode;

            DROP TRIGGER IF EXISTS payment_authorizations_status_transition ON payment_authorizations;
            DELETE FROM status_transitions
             WHERE table_name IN ('payment_authorizations', 'orders', 'payment_disputes', 'gifts', 'blind_pulls');
            ALTER TABLE payment_authorizations DROP CONSTRAINT IF EXISTS payment_authorizations_purpose_check;
            ALTER TABLE payment_authorizations ADD CONSTRAINT payment_authorizations_purpose_check
              CHECK (purpose IN ('squad', 'one_tap', 'drop')) NOT VALID;
            ALTER TABLE payment_authorizations
              DROP COLUMN IF EXISTS captured_at, DROP COLUMN IF EXISTS capture_after,
              DROP COLUMN IF EXISTS last_error, DROP COLUMN IF EXISTS next_attempt_at,
              DROP COLUMN IF EXISTS attempts, DROP COLUMN IF EXISTS stripe_payment_method_id,
              DROP COLUMN IF EXISTS stripe_payment_intent_id;
            ALTER TABLE payment_authorizations DROP CONSTRAINT IF EXISTS payment_authorizations_status_check;
            ALTER TABLE payment_authorizations ADD CONSTRAINT payment_authorizations_status_check
              CHECK (status IN ('authorized', 'captured', 'voided')) NOT VALID;

            DROP TRIGGER IF EXISTS orders_status_transition ON orders;
            DROP TRIGGER IF EXISTS orders_payment_status_transition ON orders;
            DROP FUNCTION IF EXISTS orders_payment_status_transition();
            DROP TRIGGER IF EXISTS orders_forbid_legacy ON orders;
            DROP FUNCTION IF EXISTS orders_forbid_legacy();
            ALTER TABLE orders ALTER COLUMN status SET DEFAULT 'paid';
            ALTER TABLE orders
              DROP CONSTRAINT IF EXISTS orders_paid_means_captured,
              DROP CONSTRAINT IF EXISTS orders_refund_within_total,
              DROP COLUMN IF EXISTS cancelled_at, DROP COLUMN IF EXISTS captured_at,
              DROP COLUMN IF EXISTS request_fingerprint, DROP COLUMN IF EXISTS referral_link_id,
              DROP COLUMN IF EXISTS creator_commission_minor, DROP COLUMN IF EXISTS amount_refunded_minor,
              DROP COLUMN IF EXISTS purpose, DROP COLUMN IF EXISTS payment_status;

            DROP TRIGGER IF EXISTS ledger_lines_apply_balance ON ledger_lines;
            DROP FUNCTION IF EXISTS ledger_lines_apply_balance();
            DROP TRIGGER IF EXISTS ledger_balances_protect ON ledger_balances;
            DROP FUNCTION IF EXISTS ledger_balances_protect();
            DROP TRIGGER IF EXISTS user_wallets_protect_cash ON user_wallets;
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
            CREATE TRIGGER user_wallets_protect_cash
              BEFORE UPDATE OF cash_minor ON user_wallets
              FOR EACH ROW EXECUTE FUNCTION user_wallets_protect_cash();
            DROP TABLE IF EXISTS ledger_balances;
            ALTER TABLE ledger_lines
              DROP CONSTRAINT IF EXISTS ledger_lines_amount_cap,
              DROP CONSTRAINT IF EXISTS ledger_lines_currency_check,
              DROP CONSTRAINT IF EXISTS ledger_lines_account_check;
            ALTER TABLE ledger_journals DROP CONSTRAINT IF EXISTS ledger_journals_kind_check;
            ALTER TABLE ledger_journals ADD CONSTRAINT ledger_journals_kind_check CHECK (kind IN (
              'sale', 'commission_pending', 'commission_release', 'commission_reverse', 'refund', 'price_protection'
            )) NOT VALID;
            ALTER TABLE ledger_journals
              DROP CONSTRAINT IF EXISTS ledger_journals_idempotency_key_check,
              DROP CONSTRAINT IF EXISTS ledger_journals_idempotency_key_key,
              DROP COLUMN IF EXISTS idempotency_key;

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
            SQL);
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Indexes for the hardened money paths and the catalog, built without blocking writes.
 *
 * CREATE INDEX CONCURRENTLY cannot run inside a transaction, so this migration is not wrapped in one
 * ($withinTransaction = false) and every statement is idempotent (IF NOT EXISTS / IF EXISTS): a build
 * that was interrupted leaves an INVALID index, which is dropped and rebuilt on the next run.
 *
 *  ledger_journals (order_id, kind)              per-order commission positions and reversals
 *  ledger_lines (account, currency)              account statements; balances come from ledger_balances
 *  orders (stripe_payment_intent_id) UNIQUE      webhook -> order, one order per PaymentIntent
 *  orders (buyer_id, created_at DESC)            order history, spend limit, night-hold burst
 *  orders (product_id) WHERE paid                verified purchases, related products
 *  orders (delivered_at) WHERE paid              commission release job
 *  payment_authorizations (order_id)             every payment state change
 *  payment_authorizations (status, capture_after) capture and reconcile jobs (partial: open only)
 *  blind_pulls (user_id, pulled_on) UNIQUE WHERE status <> 'failed'   replaces the plain unique key
 *  reward_events (user_id, source_id) UNIQUE WHERE kind = 'purchase'  kept-purchase reward once
 *  reward_events (user_id, kind, created_at DESC) daily engagement caps
 *  commission_ledger (order_id, referral_link_id) UNIQUE WHERE earn   referral commission once
 *  products (canonical_key), (seller_id), moderation queue
 *  products (lower(vision_category), price_minor) category matches in visual search, cheapest first
 *  price_protections (protected_until)           price-protection sweep
 * The ledger CHECKs added NOT VALID in 001000 are validated here (no write lock).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /** @var array<string, string> name => definition after "ON" */
    private const INDEXES = [
        'ledger_journals_order_kind_idx' => 'ledger_journals (order_id, kind) WHERE order_id IS NOT NULL',
        'ledger_lines_account_currency_idx' => 'ledger_lines (account, currency)',
        'orders_buyer_created_idx' => 'orders (buyer_id, created_at DESC)',
        'orders_product_paid_idx' => "orders (product_id) WHERE status = 'paid'",
        'orders_release_due_idx' => "orders (delivered_at) WHERE status = 'paid' AND delivered_at IS NOT NULL",
        'payment_authorizations_order_idx' => 'payment_authorizations (order_id)',
        'payment_authorizations_due_idx' => "payment_authorizations (status, capture_after) WHERE status IN ('pending', 'requires_action', 'authorized')",
        'reward_events_user_kind_idx' => 'reward_events (user_id, kind, created_at DESC)',
        'products_canonical_idx' => 'products (canonical_key) WHERE canonical_key IS NOT NULL',
        'products_seller_idx' => 'products (seller_id) WHERE seller_id IS NOT NULL',
        'products_moderation_idx' => "products (updated_at DESC) WHERE listing_status <> 'active'",
        'price_protections_open_idx' => 'price_protections (protected_until)',
        // Visual search by recognized category (vision_category from 001100_product_vision_attributes).
        'products_vision_category_idx' => 'products (lower(vision_category), price_minor) WHERE vision_category IS NOT NULL',
    ];

    /** @var array<string, string> */
    private const UNIQUE = [
        'orders_payment_intent_uidx' => 'orders (stripe_payment_intent_id) WHERE stripe_payment_intent_id IS NOT NULL',
        'blind_pulls_one_per_day_uidx' => "blind_pulls (user_id, pulled_on) WHERE status <> 'failed'",
        'blind_pulls_order_uidx' => 'blind_pulls (order_id) WHERE order_id IS NOT NULL',
        'reward_events_purchase_once_idx' => "reward_events (user_id, source_id) WHERE kind = 'purchase' AND source_id IS NOT NULL",
        'commission_ledger_earn_once_uidx' => "commission_ledger (order_id, referral_link_id) WHERE entry = 'earn' AND order_id IS NOT NULL",
    ];

    public function up(): void
    {
        foreach (self::UNIQUE as $name => $definition) {
            $this->build($name, 'CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS '.$name.' ON '.$definition);
        }
        foreach (self::INDEXES as $name => $definition) {
            $this->build($name, 'CREATE INDEX CONCURRENTLY IF NOT EXISTS '.$name.' ON '.$definition);
        }
        // The partial unique index now carries "one pull per day"; the old key would also block a
        // retry after a failed payment.
        DB::statement('ALTER TABLE blind_pulls DROP CONSTRAINT IF EXISTS blind_pulls_user_id_pulled_on_key');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS ledger_lines_account_idx');

        foreach (['ledger_lines_account_check', 'ledger_lines_currency_check', 'ledger_lines_amount_cap'] as $constraint) {
            try {
                DB::statement('ALTER TABLE ledger_lines VALIDATE CONSTRAINT '.$constraint);
            } catch (Throwable $legacy) {
                Log::warning("ledger_lines {$constraint} stays NOT VALID: legacy rows violate it.", ['error' => $legacy->getMessage()]);
            }
        }
    }

    public function down(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS ledger_lines_account_idx ON ledger_lines (account)');
        DB::statement('ALTER TABLE blind_pulls DROP CONSTRAINT IF EXISTS blind_pulls_user_id_pulled_on_key');
        DB::statement('ALTER TABLE blind_pulls ADD CONSTRAINT blind_pulls_user_id_pulled_on_key UNIQUE (user_id, pulled_on)');
        foreach (array_keys(self::INDEXES + self::UNIQUE) as $name) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.$name);
        }
    }

    private function build(string $name, string $statement): void
    {
        $invalid = DB::selectOne(
            'SELECT 1 AS bad FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = ? AND NOT i.indisvalid',
            [$name],
        );
        if ($invalid !== null) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.$name);
        }
        DB::statement($statement);
    }
};

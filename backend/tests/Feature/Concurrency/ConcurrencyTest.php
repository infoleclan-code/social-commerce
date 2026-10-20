<?php

declare(strict_types=1);

namespace Tests\Feature\Concurrency;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Throwable;

/**
 * Real concurrency: N separate PHP processes, each booting the application on committed data in a
 * dedicated database (social_race), released at the same instant by a PostgreSQL advisory-lock barrier.
 * Each scenario asserts the outcome that must hold under ANY interleaving.
 */
final class ConcurrencyTest extends TestCase
{
    private const DATABASE = 'social_race';

    private static bool $migrated = false;

    private Connection $race;

    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is required for multi-process tests.');
        }
        $base = config('database.connections.pgsql');
        if (! self::$migrated) {
            $this->createDatabase($base);
            $migrate = new Process([PHP_BINARY, 'artisan', 'migrate:fresh', '--force'], base_path(), $this->workerEnv(), null, 300);
            $migrate->mustRun();
            self::$migrated = true;
        }
        config(['database.connections.race' => array_merge($base, ['database' => self::DATABASE])]);
        DB::purge('race');
        $this->race = DB::connection('race');
    }

    public function test_twenty_buyers_racing_for_five_units_get_exactly_five_orders(): void
    {
        $product = $this->product(stock: 5);
        $buyers = array_map(fn (): string => $this->buyerWithCard(), range(1, 20));

        $results = $this->race('one_tap', 20, [
            'product' => $product,
            'buyers' => $buyers,
            'keys' => array_map(fn (int $i): string => 'stock-race-'.$i.'-'.Str::random(6), range(0, 19)),
        ]);

        $this->assertCount(5, array_filter($results, fn (array $r): bool => $r['ok']));
        $this->assertCount(15, array_filter($results, fn (array $r): bool => ($r['code'] ?? null) === 'OUT_OF_STOCK'), json_encode($results));
        $this->assertSame(0, (int) $this->race->table('products')->where('id', $product)->value('stock'));
        $this->assertSame(5, $this->race->table('orders')->where('product_id', $product)->count());
        $this->assertSame(5, $this->race->table('payment_authorizations')->where('product_id', $product)->where('status', 'authorized')->count());
    }

    public function test_ten_concurrent_taps_with_one_key_create_one_order_and_one_authorization(): void
    {
        $product = $this->product(stock: 50);
        $buyer = $this->buyerWithCard();
        $key = 'same-key-'.Str::random(10);

        $results = $this->race('one_tap', 10, ['product' => $product, 'buyers' => [$buyer], 'keys' => array_fill(0, 10, $key)]);

        $this->assertCount(10, array_filter($results, fn (array $r): bool => $r['ok']), json_encode($results));
        $this->assertCount(1, array_unique(array_column($results, 'order')));
        $this->assertSame(1, $this->race->table('orders')->where('buyer_id', $buyer)->count());
        $this->assertSame(1, $this->race->table('payment_authorizations')->where('buyer_id', $buyer)->count());
        $this->assertSame(49, (int) $this->race->table('products')->where('id', $product)->value('stock'));
    }

    public function test_concurrent_releases_cannot_overdraw_a_pending_balance(): void
    {
        [$order, $creator] = $this->orderWithPendingCommission(500);

        $results = $this->race('release_pending', 20, [
            'order' => $order,
            'creator' => $creator,
            'amount' => 100,
            'keys' => array_map(fn (int $i): string => 'overdraw:'.$order.':'.$i, range(0, 19)),
        ]);

        $this->assertCount(5, array_filter($results, fn (array $r): bool => $r['ok']), json_encode($results));
        $this->assertCount(15, array_filter($results, fn (array $r): bool => ($r['code'] ?? '') === 'SQLSTATE_23514'));
        $this->assertSame(0, $this->balance('user:'.$creator.':pending'));
        $this->assertSame(500, $this->balance('user:'.$creator.':available'));
        $this->assertSame(500, (int) $this->race->table('user_wallets')->where('user_id', $creator)->value('cash_minor'));
        $this->assertLedgerConsistent();
    }

    public function test_one_ledger_key_posted_by_ten_processes_is_one_journal(): void
    {
        [$order, $creator] = $this->orderWithPendingCommission(500);
        $key = 'one-key:'.$order;

        $results = $this->race('release_pending', 10, ['order' => $order, 'creator' => $creator, 'amount' => 300, 'keys' => array_fill(0, 10, $key)]);

        $this->assertCount(10, array_filter($results, fn (array $r): bool => $r['ok']), json_encode($results));
        $this->assertCount(1, array_unique(array_column($results, 'journal')));
        $this->assertSame(200, $this->balance('user:'.$creator.':pending'));
        $this->assertLedgerConsistent();
    }

    public function test_a_release_racing_a_full_refund_always_nets_to_zero(): void
    {
        [$order, $creator] = $this->orderWithPendingCommission(800, total: 10_000, deliveredDaysAgo: 30);

        $results = $this->race('release_or_refund', 10, ['order' => $order, 'total' => 10_000]);

        $this->assertCount(10, array_filter($results, fn (array $r): bool => $r['ok']), json_encode($results));
        $this->assertSame(0, $this->balance('user:'.$creator.':pending') + $this->balance('user:'.$creator.':available'));
        $this->assertLessThanOrEqual(0, $this->balance('user:'.$creator.':available'));
        $this->assertSame(0, $this->balance('user:'.$creator.':pending'));
        $this->assertLedgerConsistent();
    }

    public function test_twelve_people_joining_a_five_person_squad_leaves_exactly_five(): void
    {
        $host = $this->user();
        $squad = (string) Str::uuid7();
        $this->race->table('squads')->insert(['id' => $squad, 'product_id' => $this->product(), 'host_id' => $host, 'discount_bps_per_member' => 500, 'max_members' => 5, 'floor_price_minor' => 500, 'expires_at' => now()->addDay(), 'created_at' => now()]);
        $this->race->table('squad_members')->insert(['squad_id' => $squad, 'user_id' => $host, 'joined_at' => now()]);
        $joiners = array_map(fn (): string => $this->user(), range(1, 12));

        $results = $this->race('join_squad', 12, ['squad' => $squad, 'buyers' => $joiners]);

        $this->assertCount(4, array_filter($results, fn (array $r): bool => $r['ok']), json_encode($results));
        $this->assertCount(8, array_filter($results, fn (array $r): bool => ($r['code'] ?? null) === 'SQUAD_CLOSED'));
        $this->assertSame(5, $this->race->table('squad_members')->where('squad_id', $squad)->count());
    }

    public function test_one_webhook_delivered_eight_times_at_once_is_applied_once(): void
    {
        [$order, $creator, $intent, $product] = $this->orderWithPendingCommission(800, total: 10_000, withIntent: true);
        $stock = (int) $this->race->table('products')->where('id', $product)->value('stock');
        $event = [
            'id' => 'evt_race_'.Str::random(12),
            'object' => 'event',
            'type' => 'charge.refunded',
            'livemode' => false,
            'data' => ['object' => ['id' => 'ch_race', 'object' => 'charge', 'payment_intent' => $intent, 'amount_refunded' => 10_000, 'currency' => 'usd']],
        ];

        $results = $this->race('webhook', 8, ['event' => $event]);

        $this->assertCount(8, array_filter($results, fn (array $r): bool => $r['ok']), json_encode($results));
        $this->assertSame(['cancelled', 'refunded'], [(string) $this->race->table('orders')->where('id', $order)->value('status'), (string) $this->race->table('orders')->where('id', $order)->value('payment_status')]);
        $this->assertSame($stock + 1, (int) $this->race->table('products')->where('id', $product)->value('stock'), 'Restocked once.');
        $this->assertSame(1, $this->race->table('ledger_journals')->where('order_id', $order)->where('kind', 'refund')->count());
        $this->assertSame(1, $this->race->table('ledger_journals')->where('order_id', $order)->where('kind', 'commission_reverse')->count());
        $this->assertSame(1, $this->race->table('stripe_events')->where('id', $event['id'])->count());
        $this->assertSame(0, $this->balance('user:'.$creator.':pending'));
        $this->assertLedgerConsistent();
    }

    public function test_four_capture_workers_capture_each_authorization_exactly_once(): void
    {
        $intents = [];
        for ($i = 0; $i < 10; $i++) {
            $intents[] = $this->authorizedOrderDue();
        }

        $results = $this->race('capture_due', 4, ['intents' => $intents]);

        $this->assertCount(4, array_filter($results, fn (array $r): bool => $r['ok']), json_encode($results));
        $this->assertSame(10, array_sum(array_column($results, 'claimed')));
        $this->assertSame(10, array_sum(array_column($results, 'captures')));
        $orders = array_column($intents, 'order');
        $this->assertSame(10, $this->race->table('orders')->whereIn('id', $orders)->where('payment_status', 'captured')->count());
        $this->assertSame(10, $this->race->table('ledger_journals')->whereIn('order_id', $orders)->where('kind', 'sale')->count());
        $this->assertLedgerConsistent();
    }

    /**
     * Runs $workers processes on one scenario and returns their JSON results.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function race(string $scenario, int $workers, array $payload): array
    {
        $barrier = random_int(1_000_000, 2_000_000_000);
        $file = tempnam(sys_get_temp_dir(), 'race');
        file_put_contents($file, json_encode(['scenario' => $scenario, 'barrier' => $barrier, 'env' => $this->workerEnv()] + $payload, JSON_THROW_ON_ERROR));
        chmod($file, 0600);
        $gate = DB::connection('race');
        $gate->selectOne('SELECT pg_advisory_lock(?)', [$barrier]);
        $processes = [];
        try {
            foreach (range(0, $workers - 1) as $index) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/race_worker.php'), $file, (string) $index], base_path(), null, null, 120);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 60;
            do {
                usleep(50_000);
                $waiting = (int) $gate->selectOne(
                    "SELECT count(*) AS n FROM pg_locks WHERE locktype = 'advisory' AND NOT granted AND objid = ?",
                    [$barrier],
                )->n;
            } while ($waiting < $workers && microtime(true) < $deadline && $this->anyRunning($processes));
            $gate->selectOne('SELECT pg_advisory_unlock(?)', [$barrier]);
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $line = trim((string) strrchr("\n".trim($process->getOutput()), "\n"));
                $decoded = json_decode($line, true);
                $this->assertIsArray($decoded, 'Worker output: '.$process->getOutput().$process->getErrorOutput());
                $results[] = $decoded;
            }
        } finally {
            @unlink($file);
            $gate->selectOne('SELECT pg_advisory_unlock_all()');
        }

        return $results;
    }

    /** @param  list<Process>  $processes */
    private function anyRunning(array $processes): bool
    {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, string> */
    private function workerEnv(): array
    {
        $db = config('database.connections.pgsql');

        return [
            'APP_ENV' => 'testing',
            'APP_KEY' => (string) config('app.key'),
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => (string) $db['host'],
            'DB_PORT' => (string) $db['port'],
            'DB_DATABASE' => self::DATABASE,
            'DB_USERNAME' => (string) $db['username'],
            'DB_PASSWORD' => (string) $db['password'],
            'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array',
            'STRIPE_WEBHOOK_SECRET' => 'whsec_race',
        ];
    }

    /** @param  array<string, mixed>  $base */
    private function createDatabase(array $base): void
    {
        try {
            $pdo = new PDO('pgsql:host='.$base['host'].';port='.$base['port'].';dbname=postgres', $base['username'], $base['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            if ($pdo->query("SELECT 1 FROM pg_database WHERE datname = '".self::DATABASE."'")->fetchColumn() === false) {
                $pdo->exec('CREATE DATABASE '.self::DATABASE);
            }
        } catch (Throwable $exception) {
            $this->markTestSkipped('Cannot create the '.self::DATABASE.' database: '.$exception->getMessage());
        }
    }

    private function user(): string
    {
        $id = (string) Str::uuid7();
        $this->race->table('users')->insert([
            'id' => $id,
            'email' => 'race-'.$id.'@example.com',
            'username' => 'r'.substr(str_replace('-', '', $id), -14),
            'display_name' => 'Racer',
            'social_feed_enabled' => true,
        ]);

        return $id;
    }

    private function buyerWithCard(): string
    {
        $id = $this->user();
        $this->race->table('users')->where('id', $id)->update(['stripe_customer_id' => 'cus_race_'.substr(str_replace('-', '', $id), -12)]);
        $this->race->table('saved_payment_methods')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $id,
            'stripe_payment_method_id' => 'pm_race_'.substr(str_replace('-', '', $id), -12),
            'brand' => 'visa',
            'last4' => '4242',
            'is_default' => true,
            'created_at' => now(),
        ]);
        $this->race->table('spend_limits')->insert(['user_id' => $id, 'monthly_limit_minor' => null, 'night_hold' => false]);

        return $id;
    }

    private function product(int $stock = 5, int $price = 1_000): string
    {
        $id = (string) Str::uuid7();
        $this->race->table('products')->insert([
            'id' => $id,
            'title' => 'Race '.substr($id, -6),
            'brand' => 'Race',
            'image_url' => 'https://example.com/race.jpg',
            'price_minor' => $price,
            'squad_price_minor' => intdiv($price * 7, 10),
            'slug' => 'race-'.str_replace('-', '', $id),
            'currency' => 'USD',
            'stock' => $stock,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /** @return array{0: string, 1: string, 2: string, 3: string} order, creator, intent, product */
    private function orderWithPendingCommission(int $commission, int $total = 10_000, int $deliveredDaysAgo = 1, bool $withIntent = false): array
    {
        $buyer = $this->user();
        $creator = $this->user();
        $product = $this->product();
        $order = (string) Str::uuid7();
        $intent = 'pi_race_'.Str::random(12);
        $at = now()->subDays($deliveredDaysAgo);
        $this->race->table('orders')->insert([
            'id' => $order, 'buyer_id' => $buyer, 'product_id' => $product, 'creator_id' => $creator, 'quantity' => 1,
            'unit_price_minor' => $total, 'total_minor' => $total, 'currency' => 'USD', 'status' => 'paid', 'payment_status' => 'captured',
            'purpose' => 'checkout', 'stripe_payment_intent_id' => $withIntent ? $intent : null, 'captured_at' => $at, 'delivered_at' => $at,
            'idempotency_key' => 'race-'.$order, 'created_at' => $at,
        ]);
        $this->race->table('payment_authorizations')->insert([
            'id' => (string) Str::uuid7(), 'buyer_id' => $buyer, 'product_id' => $product, 'order_id' => $order, 'purpose' => 'checkout',
            'status' => 'captured', 'authorized_minor' => $total, 'captured_minor' => $total, 'currency' => 'USD',
            'idempotency_key' => 'race-'.$order, 'stripe_payment_intent_id' => $withIntent ? $intent : null, 'expires_at' => $at, 'capture_after' => $at, 'created_at' => $at,
        ]);
        $this->postJournal('sale', $order, 'platform:card_clearing', 'platform:sales', $total, 'sale:'.$order);
        $this->postJournal('commission_pending', $order, 'platform:commission', 'user:'.$creator.':pending', $commission, 'commission_pending:'.$order.':'.$creator);

        return [$order, $creator, $intent, $product];
    }

    /** @return array{id: string, amount: int, order: string} */
    private function authorizedOrderDue(): array
    {
        $buyer = $this->buyerWithCard();
        $product = $this->product();
        $order = (string) Str::uuid7();
        $intent = 'pi_race_cap_'.Str::random(12);
        $this->race->table('orders')->insert([
            'id' => $order, 'buyer_id' => $buyer, 'product_id' => $product, 'quantity' => 1, 'unit_price_minor' => 1_000, 'total_minor' => 1_000,
            'currency' => 'USD', 'status' => 'processing', 'payment_status' => 'authorized', 'purpose' => 'one_tap',
            'stripe_payment_intent_id' => $intent, 'idempotency_key' => 'cap-'.$order, 'created_at' => now(),
        ]);
        $this->race->table('payment_authorizations')->insert([
            'id' => (string) Str::uuid7(), 'buyer_id' => $buyer, 'product_id' => $product, 'order_id' => $order, 'purpose' => 'one_tap',
            'status' => 'authorized', 'authorized_minor' => 1_000, 'currency' => 'USD', 'idempotency_key' => 'cap-'.$order,
            'stripe_payment_intent_id' => $intent, 'expires_at' => now()->subMinute(), 'capture_after' => now()->subMinute(), 'created_at' => now(),
        ]);

        return ['id' => $intent, 'amount' => 1_000, 'order' => $order];
    }

    private function postJournal(string $kind, string $order, string $debit, string $credit, int $amount, string $key): void
    {
        $this->race->transaction(function () use ($kind, $order, $debit, $credit, $amount, $key): void {
            $journal = (string) Str::uuid7();
            $this->race->table('ledger_journals')->insert(['id' => $journal, 'kind' => $kind, 'order_id' => $order, 'idempotency_key' => $key, 'created_at' => now()]);
            $this->race->table('ledger_lines')->insert([
                ['id' => (string) Str::uuid7(), 'journal_id' => $journal, 'account' => $debit, 'direction' => 'debit', 'amount_minor' => $amount, 'currency' => 'USD', 'created_at' => now()],
                ['id' => (string) Str::uuid7(), 'journal_id' => $journal, 'account' => $credit, 'direction' => 'credit', 'amount_minor' => $amount, 'currency' => 'USD', 'created_at' => now()],
            ]);
        });
    }

    private function balance(string $account): int
    {
        return (int) $this->race->table('ledger_balances')->where('account', $account)->where('currency', 'USD')->sum('balance_minor');
    }

    private function assertLedgerConsistent(): void
    {
        $drift = $this->race->select(
            "SELECT l.account FROM (SELECT account, currency, SUM(CASE WHEN direction = 'credit' THEN amount_minor ELSE -amount_minor END) AS total FROM ledger_lines GROUP BY 1, 2) l
               LEFT JOIN (SELECT account, currency, SUM(balance_minor) AS total FROM ledger_balances GROUP BY 1, 2) b USING (account, currency)
              WHERE l.total IS DISTINCT FROM b.total",
        );
        $this->assertSame([], $drift, 'ledger_balances equals the sum of ledger_lines.');
    }
}

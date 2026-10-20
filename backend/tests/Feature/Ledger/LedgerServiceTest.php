<?php

declare(strict_types=1);

namespace Tests\Feature\Ledger;

use App\Services\Commerce\LedgerService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsCommerce;
use Tests\Support\BuildsSocial;
use Tests\TestCase;

/**
 * LedgerService and the database rules behind it: every invalid posting is refused before or at
 * commit, every valid one is applied once, and balances always equal the sum of the lines.
 */
final class LedgerServiceTest extends TestCase
{
    use BuildsCommerce;
    use BuildsSocial;
    use RefreshDatabase;

    private LedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = app(LedgerService::class);
    }

    /** @return iterable<string, array{0: string, 1: array<string, mixed>}> */
    public static function invalidPostings(): iterable
    {
        $ok = fn (string $account, string $direction, int $amount = 100, string $currency = 'USD'): array => compact('account', 'direction') + ['amount_minor' => $amount, 'currency' => $currency];
        yield 'unknown kind' => ['Unknown ledger journal kind', ['kind' => 'payout', 'lines' => [$ok('platform:alpha', 'debit'), $ok('platform:beta', 'credit')]]];
        yield 'one line' => ['at least two lines', ['lines' => [$ok('platform:alpha', 'debit')]]];
        yield 'no lines' => ['at least two lines', ['lines' => []]];
        yield 'unbalanced' => ['not balanced in USD', ['lines' => [$ok('platform:alpha', 'debit', 100), $ok('platform:beta', 'credit', 99)]]];
        yield 'balanced across currencies only' => ['not balanced in', ['lines' => [$ok('platform:alpha', 'debit', 100, 'USD'), $ok('platform:beta', 'credit', 100, 'EUR')]]];
        yield 'zero amount' => ['positive integer', ['lines' => [$ok('platform:alpha', 'debit', 0), $ok('platform:beta', 'credit', 0)]]];
        yield 'negative amount' => ['positive integer', ['lines' => [$ok('platform:alpha', 'debit', -5), $ok('platform:beta', 'credit', -5)]]];
        yield 'float amount' => ['positive integer', ['lines' => [['account' => 'platform:alpha', 'direction' => 'debit', 'amount_minor' => 1.5, 'currency' => 'USD'], ['account' => 'platform:beta', 'direction' => 'credit', 'amount_minor' => 1.5, 'currency' => 'USD']]]];
        yield 'numeric string amount' => ['positive integer', ['lines' => [['account' => 'platform:alpha', 'direction' => 'debit', 'amount_minor' => '100', 'currency' => 'USD'], $ok('platform:beta', 'credit')]]];
        yield 'absurd amount' => ['positive integer', ['lines' => [$ok('platform:alpha', 'debit', PHP_INT_MAX), $ok('platform:beta', 'credit', PHP_INT_MAX)]]];
        yield 'lowercase currency' => ['ISO 4217', ['lines' => [$ok('platform:alpha', 'debit', 100, 'usd'), $ok('platform:beta', 'credit', 100, 'usd')]]];
        yield 'bad direction' => ['debit or credit', ['lines' => [$ok('platform:alpha', 'DEBIT'), $ok('platform:beta', 'credit')]]];
        yield 'account injection' => ['Invalid ledger account', ['lines' => [$ok("platform:alpha'; DROP TABLE ledger_lines;--", 'debit'), $ok('platform:beta', 'credit')]]];
        yield 'user account without uuid' => ['Invalid ledger account', ['lines' => [$ok('user:admin:available', 'debit'), $ok('platform:beta', 'credit')]]];
        yield 'unknown user bucket' => ['Invalid ledger account', ['lines' => [$ok('user:0199e7a0-0000-7000-8000-000000000001:bonus', 'debit'), $ok('platform:beta', 'credit')]]];
        yield 'short key' => ['idempotency key', ['key' => 'k1', 'lines' => [$ok('platform:alpha', 'debit'), $ok('platform:beta', 'credit')]]];
        yield 'key with spaces' => ['idempotency key', ['key' => 'a key with spaces', 'lines' => [$ok('platform:alpha', 'debit'), $ok('platform:beta', 'credit')]]];
        yield 'order id not a uuid' => ['order id must be a UUID', ['order' => '1 OR 1=1', 'lines' => [$ok('platform:alpha', 'debit'), $ok('platform:beta', 'credit')]]];
    }

    /** @param  array<string, mixed>  $case */
    #[DataProvider('invalidPostings')]
    public function test_invalid_postings_are_refused_before_touching_the_database(string $reason, array $case): void
    {
        $journals = DB::table('ledger_journals')->count();
        try {
            $this->ledger->post($case['kind'] ?? 'sale', $case['order'] ?? null, $case['lines'], $case['key'] ?? 'test:invalid:'.Str::random(8));
            $this->fail('An invalid posting was accepted.');
        } catch (InvalidArgumentException $refused) {
            $this->assertStringContainsString($reason, $refused->getMessage());
            $this->assertSame($journals, DB::table('ledger_journals')->count());
        }
    }

    public function test_a_posting_is_applied_once_per_key_and_a_reused_key_is_refused(): void
    {
        $lines = $this->pair('platform:alpha', 'platform:beta', 250);
        $first = $this->ledger->post('sale', null, $lines, 'test:once:0001');
        $again = $this->ledger->post('sale', null, $lines, 'test:once:0001');

        $this->assertSame($first, $again);
        $this->assertSame(1, DB::table('ledger_journals')->where('idempotency_key', 'test:once:0001')->count());
        $this->assertSame(2, DB::table('ledger_lines')->where('journal_id', $first)->count());
        $this->assertSame(250, $this->ledger->balance('platform:beta'));

        $this->expectException(LogicException::class);
        $this->ledger->post('refund', null, $lines, 'test:once:0001');
    }

    public function test_balances_always_equal_the_sum_of_lines(): void
    {
        mt_srand(20261014);
        $accounts = ['platform:alpha', 'platform:beta', 'platform:gamma', 'platform:commission'];
        for ($i = 0; $i < 150; $i++) {
            [$from, $to] = [$accounts[mt_rand(0, 3)], $accounts[mt_rand(0, 3)]];
            if ($from === $to) {
                continue;
            }
            $currency = ['USD', 'EUR', 'GEL'][mt_rand(0, 2)];
            $this->ledger->post('sale', null, $this->pair($from, $to, mt_rand(1, 100_000), $currency), 'test:fuzz:'.$i);
        }

        $expected = DB::table('ledger_lines')
            ->groupBy('account', 'currency')
            ->get(['account', 'currency', DB::raw("SUM(CASE WHEN direction = 'credit' THEN amount_minor ELSE -amount_minor END) AS total")]);
        $this->assertNotEmpty($expected);
        foreach ($expected as $row) {
            $this->assertSame((int) $row->total, $this->ledger->balance((string) $row->account, (string) $row->currency), $row->account.' '.$row->currency);
        }
        // Platform accounts are sharded so every checkout does not queue on one row lock.
        $this->assertGreaterThan(1, (int) DB::table('ledger_balances')->where('account', 'platform:commission')->count());
        $this->assertSame(0, (int) DB::table('ledger_balances')->where('account', 'like', 'user:%')->where('shard', '<>', 0)->count());
        // Every journal balances per currency, so the whole ledger sums to zero per currency.
        foreach (DB::table('ledger_balances')->groupBy('currency')->get(['currency', DB::raw('SUM(balance_minor) AS total')]) as $row) {
            $this->assertSame(0, (int) $row->total);
        }
    }

    public function test_pending_never_goes_negative_and_available_only_through_reversals(): void
    {
        $creator = $this->makeUser();
        $order = $this->capturedOrder($this->makeUser(), $this->catalogProduct(), 10_000);
        $this->ledger->pendCommission($creator->id, $order, 500, 'USD');

        try {
            $this->ledger->post('commission_release', $order, $this->pair(LedgerService::pending($creator->id), LedgerService::available($creator->id), 600), 'test:overdraw:pending');
            $this->fail('Pending went negative.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('cannot go below zero', $exception->getMessage());
        }
        try {
            $this->ledger->post('sale', $order, $this->pair(LedgerService::available($creator->id), 'platform:sales', 100), 'test:overdraw:available');
            $this->fail('Available went negative outside a reversal.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('insufficient available balance', $exception->getMessage());
        }
        $this->ledger->post('commission_reverse', $order, $this->pair(LedgerService::available($creator->id), LedgerService::PLATFORM_COMMISSION, 100), 'test:clawback');
        $this->assertSame(-100, $this->ledger->balance(LedgerService::available($creator->id)));
        $this->assertSame(0, (int) DB::table('user_wallets')->where('user_id', $creator->id)->value('cash_minor'), 'The wallet mirror never shows a negative balance.');
        $this->assertSame(500, $this->ledger->balance(LedgerService::pending($creator->id)));
    }

    public function test_commission_lifecycle_release_partial_refund_full_refund_and_replays(): void
    {
        $creator = $this->makeUser();
        $order = $this->capturedOrder($this->makeUser(), $this->catalogProduct(10_000), 10_000, [
            'creator_id' => $creator->id,
            'created_at' => now()->subDays(20),
        ]);
        $this->ledger->pendCommission($creator->id, $order, 801, 'USD');
        $this->ledger->pendCommission($creator->id, $order, 801, 'USD');
        $this->assertSame(801, $this->ledger->balance(LedgerService::pending($creator->id)), 'The same commission twice is one commission.');

        $this->assertSame(1, $this->ledger->releaseKept($order));
        $this->assertSame(0, $this->ledger->releaseKept($order));
        $this->assertSame(0, $this->ledger->balance(LedgerService::pending($creator->id)));
        $this->assertSame(801, $this->ledger->balance(LedgerService::available($creator->id)));

        // A quarter refunded: the creator keeps floor(801 * 7500 / 10000) = 600.
        $this->ledger->settleCommissions($order, 2_500, 10_000);
        $this->ledger->settleCommissions($order, 2_500, 10_000);
        $this->assertSame(600, $this->ledger->balance(LedgerService::available($creator->id)));

        $this->ledger->settleCommissions($order, 10_000, 10_000);
        $this->ledger->settleCommissions($order, 10_000, 10_000);
        $this->assertSame(0, $this->ledger->balance(LedgerService::available($creator->id)));
        $this->assertSame(0, $this->ledger->balance(LedgerService::pending($creator->id)));
        $this->assertSame(0, (int) DB::table('ledger_balances')->where('account', LedgerService::PLATFORM_COMMISSION)->sum('balance_minor'));
        $this->assertSame(1, DB::table('reward_events')->where('kind', 'purchase')->where('source_id', $order)->count(), 'The kept-purchase reward is granted once.');
    }

    public function test_a_refund_before_release_only_releases_what_is_left(): void
    {
        $creator = $this->makeUser();
        $order = $this->capturedOrder($this->makeUser(), $this->catalogProduct(10_000), 10_000, [
            'creator_id' => $creator->id,
            'created_at' => now()->subDays(20),
        ]);
        $this->ledger->pendCommission($creator->id, $order, 1_000, 'USD');
        $this->ledger->settleCommissions($order, 5_000, 10_000);
        $this->assertSame(500, $this->ledger->balance(LedgerService::pending($creator->id)));

        DB::table('orders')->where('id', $order)->update(['payment_status' => 'partially_refunded', 'amount_refunded_minor' => 5_000]);
        $this->ledger->releaseKept($order);

        $this->assertSame(0, $this->ledger->balance(LedgerService::pending($creator->id)));
        $this->assertSame(500, $this->ledger->balance(LedgerService::available($creator->id)));
    }

    public function test_nothing_is_released_inside_the_window_or_while_disputed_or_unpaid(): void
    {
        $creator = $this->makeUser();
        $buyer = $this->makeUser();
        $recent = $this->capturedOrder($buyer, $this->catalogProduct(), 1_000, ['creator_id' => $creator->id, 'created_at' => now()->subDays(13)]);
        $disputed = $this->capturedOrder($buyer, $this->catalogProduct(), 1_000, ['creator_id' => $creator->id, 'created_at' => now()->subDays(30)]);
        DB::table('orders')->where('id', $disputed)->update(['payment_status' => 'disputed']);
        foreach ([$recent, $disputed] as $order) {
            $this->ledger->pendCommission($creator->id, $order, 100, 'USD');
            $this->assertSame(0, $this->ledger->releaseKept($order));
        }
        $this->assertSame(200, $this->ledger->balance(LedgerService::pending($creator->id)));
        $this->assertSame(0, $this->ledger->releaseKept((string) Str::uuid7()));
    }

    public function test_card_money_and_refunds_are_posted_by_cumulative_amount(): void
    {
        $order = $this->capturedOrder($this->makeUser(), $this->catalogProduct(), 4_000);
        $this->ledger->recordSale($order, 4_000, 'USD');
        $this->ledger->recordSale($order, 4_000, 'USD');
        $this->ledger->recordRefund($order, 1_000, 'USD');
        $this->ledger->recordRefund($order, 1_000, 'USD');
        $this->ledger->recordRefund($order, 500, 'USD');
        $this->ledger->recordRefund($order, 4_000, 'USD');

        $this->assertSame(0, $this->ledger->balance(LedgerService::PLATFORM_CARD_CLEARING));
        $this->assertSame(0, $this->ledger->balance(LedgerService::PLATFORM_SALES));
        $this->assertSame(1, DB::table('ledger_journals')->where('order_id', $order)->where('kind', 'sale')->count());
        $this->assertSame(2, DB::table('ledger_journals')->where('order_id', $order)->where('kind', 'refund')->count());
        $this->assertNull($this->ledger->recordSale($order, 0, 'USD'));
    }

    public function test_price_protection_credits_and_reversals_are_cumulative(): void
    {
        $buyer = $this->makeUser();
        $order = $this->capturedOrder($buyer, $this->catalogProduct(), 10_000);
        $this->ledger->creditPriceProtection($order, $buyer->id, 1_000, 'USD');
        $this->ledger->creditPriceProtection($order, $buyer->id, 1_000, 'USD');
        $this->ledger->creditPriceProtection($order, $buyer->id, 1_500, 'USD');
        $this->assertSame(1_500, $this->ledger->balance(LedgerService::available($buyer->id)));
        $this->assertSame(1_500, (int) DB::table('user_wallets')->where('user_id', $buyer->id)->value('cash_minor'));

        $this->ledger->settlePriceProtection($order, 5_000, 10_000);
        $this->assertSame(750, $this->ledger->balance(LedgerService::available($buyer->id)));
        $this->ledger->reverseOrder($order);
        $this->assertSame(0, $this->ledger->balance(LedgerService::available($buyer->id)));
        $this->assertSame(0, (int) DB::table('ledger_balances')->where('account', LedgerService::PLATFORM_PRICE_PROTECTION)->sum('balance_minor'));
    }

    public function test_the_database_refuses_edits_and_unbalanced_journals_even_without_the_service(): void
    {
        $journal = $this->ledger->post('sale', null, $this->pair('platform:alpha', 'platform:beta', 100), 'test:immutable:1');
        foreach ([
            fn () => DB::table('ledger_lines')->where('journal_id', $journal)->update(['amount_minor' => 1]),
            fn () => DB::table('ledger_lines')->where('journal_id', $journal)->delete(),
            fn () => DB::table('ledger_journals')->where('id', $journal)->delete(),
            fn () => DB::table('ledger_balances')->where('account', 'platform:beta')->update(['balance_minor' => 1_000_000]),
            fn () => DB::table('ledger_balances')->insert(['account' => 'platform:free', 'currency' => 'USD', 'shard' => 0, 'balance_minor' => 1_000_000]),
            fn () => DB::table('ledger_balances')->where('account', 'platform:beta')->delete(),
        ] as $index => $tamper) {
            try {
                DB::transaction($tamper);
                $this->fail("Tampering #{$index} succeeded.");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** @return list<array{account: string, direction: string, amount_minor: int, currency: string}> */
    private function pair(string $from, string $to, int $amount, string $currency = 'USD'): array
    {
        return [
            ['account' => $from, 'direction' => 'debit', 'amount_minor' => $amount, 'currency' => $currency],
            ['account' => $to, 'direction' => 'credit', 'amount_minor' => $amount, 'currency' => $currency],
        ];
    }
}

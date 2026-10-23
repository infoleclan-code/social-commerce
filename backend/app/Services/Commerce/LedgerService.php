<?php

declare(strict_types=1);

namespace App\Services\Commerce;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * Append-only double-entry ledger.
 *
 * Guarantees, and where each one is enforced
 *  - a journal posts at most once: every posting carries an idempotency key, unique in the database
 *    (ON CONFLICT DO NOTHING returns the existing journal on a retry or a concurrent duplicate);
 *  - a journal balances per currency and has at least two lines (validated here, and again by a
 *    deferred constraint trigger at commit);
 *  - balances are read from ledger_balances, which a trigger updates in the same transaction as the
 *    lines: user:{id}:pending can never go negative, user:{id}:available only through a reversal;
 *  - user_wallets.cash_minor mirrors user:{id}:available from that trigger, never from PHP;
 *  - order-level operations (release, reversal) run under a per-order advisory lock and are computed
 *    from what is already posted, so a release racing a refund nets out to the right amount.
 *
 * Lines are inserted sorted by account, so concurrent journals lock balance rows in one global order
 * (no deadlocks between, say, a release and a reversal for the same creator).
 */
final class LedgerService
{
    public const PLATFORM_COMMISSION = 'platform:commission';

    public const PLATFORM_PRICE_PROTECTION = 'platform:price_protection';

    public const PLATFORM_CARD_CLEARING = 'platform:card_clearing';

    public const PLATFORM_SALES = 'platform:sales';

    public const PLATFORM_FEE = 'platform:fee';

    public const KINDS = [
        'sale', 'commission_pending', 'commission_release', 'commission_reverse', 'refund',
        'price_protection', 'price_protection_reverse', 'platform_fee', 'platform_fee_reverse',
    ];

    /** Days after delivery before a creator's commission becomes withdrawable (the return window). */
    public const RELEASE_AFTER_DAYS = 14;

    private const ACCOUNT_PATTERN = '/^(platform:[a-z0-9_-]{2,60}|user:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}:(pending|available))$/';

    private const KEY_PATTERN = '/^[A-Za-z0-9:_.\-]{8,200}$/';

    private const MAX_AMOUNT_MINOR = 1_000_000_000_000;

    public function __construct(private readonly RewardService $rewards) {}

    /**
     * Posts one journal, or returns the journal already posted under this key.
     *
     * @param  list<array{account: string, direction: string, amount_minor: int, currency: string}>  $lines
     */
    public function post(string $kind, ?string $orderId, array $lines, string $idempotencyKey): string
    {
        $this->validate($kind, $orderId, $lines, $idempotencyKey);
        usort($lines, fn (array $left, array $right): int => [$left['account'], $left['currency'], $left['direction']]
            <=> [$right['account'], $right['currency'], $right['direction']]);

        return DB::transaction(function () use ($kind, $orderId, $lines, $idempotencyKey): string {
            $journalId = (string) Str::uuid7();
            $inserted = DB::selectOne(
                'INSERT INTO ledger_journals (id, kind, order_id, idempotency_key, created_at)
                 VALUES (?, ?, ?, ?, now())
                 ON CONFLICT (idempotency_key) DO NOTHING
                 RETURNING id',
                [$journalId, $kind, $orderId, $idempotencyKey],
            );
            if ($inserted === null) {
                $existing = DB::table('ledger_journals')->where('idempotency_key', $idempotencyKey)->first(['id', 'kind', 'order_id']);
                if ($existing === null || $existing->kind !== $kind || (string) $existing->order_id !== (string) $orderId) {
                    throw new LogicException('Ledger idempotency key reused for a different posting.');
                }

                return (string) $existing->id;
            }

            $now = now();
            DB::table('ledger_lines')->insert(array_map(fn (array $line): array => [
                'id' => (string) Str::uuid7(),
                'journal_id' => $journalId,
                'account' => $line['account'],
                'direction' => $line['direction'],
                'amount_minor' => $line['amount_minor'],
                'currency' => $line['currency'],
                'created_at' => $now,
            ], $lines));

            return $journalId;
        });
    }

    /** Balance of one account in one currency: credits minus debits, O(1) from ledger_balances. */
    public function balance(string $account, string $currency = 'USD'): int
    {
        return (int) DB::table('ledger_balances')
            ->where('account', $account)
            ->where('currency', $currency)
            ->sum('balance_minor');
    }

    // ---------------------------------------------------------------- card money

    /** Card money captured for an order. */
    public function recordSale(string $orderId, int $amountMinor, string $currency): ?string
    {
        if ($amountMinor < 1) {
            return null;
        }

        return $this->post('sale', $orderId, [
            ['account' => self::PLATFORM_CARD_CLEARING, 'direction' => 'debit', 'amount_minor' => $amountMinor, 'currency' => $currency],
            ['account' => self::PLATFORM_SALES, 'direction' => 'credit', 'amount_minor' => $amountMinor, 'currency' => $currency],
        ], 'sale:'.$orderId);
    }

    /**
     * Card money returned for an order. $refundedTotalMinor is the cumulative refunded amount, so the
     * same webhook delivered twice, or two refunds arriving out of order, post exactly the difference.
     */
    public function recordRefund(string $orderId, int $refundedTotalMinor, string $currency): ?string
    {
        return DB::transaction(function () use ($orderId, $refundedTotalMinor, $currency): ?string {
            $this->lockOrder($orderId);
            $posted = (int) DB::table('ledger_lines as l')
                ->join('ledger_journals as j', 'j.id', '=', 'l.journal_id')
                ->where('j.order_id', $orderId)
                ->where('j.kind', 'refund')
                ->where('l.account', self::PLATFORM_CARD_CLEARING)
                ->where('l.direction', 'credit')
                ->sum('l.amount_minor');
            $delta = $refundedTotalMinor - $posted;
            if ($delta < 1) {
                return null;
            }

            return $this->post('refund', $orderId, [
                ['account' => self::PLATFORM_SALES, 'direction' => 'debit', 'amount_minor' => $delta, 'currency' => $currency],
                ['account' => self::PLATFORM_CARD_CLEARING, 'direction' => 'credit', 'amount_minor' => $delta, 'currency' => $currency],
            ], 'refund:'.$orderId.':'.$refundedTotalMinor);
        });
    }

    // ---------------------------------------------------------------- creator commissions

    /** A creator's commission on a captured order, held as pending until the return window closes. */
    public function pendCommission(string $userId, string $orderId, int $amountMinor, string $currency): ?string
    {
        if ($amountMinor < 1) {
            return null;
        }

        return $this->post('commission_pending', $orderId, [
            ['account' => self::PLATFORM_COMMISSION, 'direction' => 'debit', 'amount_minor' => $amountMinor, 'currency' => $currency],
            ['account' => self::pending($userId), 'direction' => 'credit', 'amount_minor' => $amountMinor, 'currency' => $currency],
        ], 'commission_pending:'.$orderId.':'.$userId);
    }

    /**
     * Moves whatever is still pending for a kept order to available, once the return window has closed.
     * An order that is refunded, disputed, or not yet delivered releases nothing. Returns the number of
     * creators paid.
     */
    public function releaseKept(string $orderId): int
    {
        $released = DB::transaction(function () use ($orderId): int {
            $order = $this->lockOrder($orderId);
            if (! $this->releasable($order)) {
                return 0;
            }
            $count = 0;
            foreach ($this->commissionPositions($orderId) as $userId => $position) {
                $stillPending = $position['granted'] - $position['released'] - $position['reversed_pending'];
                if ($stillPending < 1) {
                    continue;
                }
                $this->post('commission_release', $orderId, [
                    ['account' => self::pending($userId), 'direction' => 'debit', 'amount_minor' => $stillPending, 'currency' => $position['currency']],
                    ['account' => self::available($userId), 'direction' => 'credit', 'amount_minor' => $stillPending, 'currency' => $position['currency']],
                ], 'commission_release:'.$orderId.':'.$userId);
                $count++;
            }

            return $count;
        });
        $this->grantKeptPurchase($orderId);

        return $released;
    }

    /**
     * Brings every commission on the order down to its share of what the buyer kept:
     * floor(commission * kept / total). Takes from pending first, then from available (which may go
     * negative: the creator owes it back from future earnings). Safe to call any number of times.
     */
    public function settleCommissions(string $orderId, int $refundedTotalMinor, int $orderTotalMinor): void
    {
        DB::transaction(function () use ($orderId, $refundedTotalMinor, $orderTotalMinor): void {
            $this->lockOrder($orderId);
            $kept = max(0, $orderTotalMinor - $refundedTotalMinor);
            foreach ($this->commissionPositions($orderId) as $userId => $position) {
                $target = $orderTotalMinor < 1 ? 0 : intdiv($position['granted'] * $kept, $orderTotalMinor);
                $held = $position['granted'] - $position['reversed_pending'] - $position['reversed_available'];
                $delta = $held - $target;
                if ($delta < 1) {
                    continue;
                }
                $stillPending = max(0, $position['granted'] - $position['released'] - $position['reversed_pending']);
                $fromPending = min($delta, $stillPending);
                $fromAvailable = $delta - $fromPending;
                $currency = $position['currency'];
                $lines = [['account' => self::PLATFORM_COMMISSION, 'direction' => 'credit', 'amount_minor' => $delta, 'currency' => $currency]];
                if ($fromPending > 0) {
                    $lines[] = ['account' => self::pending($userId), 'direction' => 'debit', 'amount_minor' => $fromPending, 'currency' => $currency];
                }
                if ($fromAvailable > 0) {
                    $lines[] = ['account' => self::available($userId), 'direction' => 'debit', 'amount_minor' => $fromAvailable, 'currency' => $currency];
                }
                $this->post('commission_reverse', $orderId, $lines, 'commission_reverse:'.$orderId.':'.$userId.':'.$target);
            }
        });
    }

    /** Full reversal: the order was cancelled, withdrawn, fully refunded, or lost a dispute. */
    public function reverseOrder(string $orderId): void
    {
        $total = (int) DB::table('orders')->where('id', $orderId)->value('total_minor');
        $this->settleCommissions($orderId, $total, $total);
        $this->settlePriceProtection($orderId, $total, $total);
        $this->settlePlatformFee($orderId, $total, $total);
    }

    /**
     * The platform's fee on a captured order. Debiting sales and crediting platform:fee splits the
     * sale journal (which credited sales for the whole charge) into seller proceeds and platform revenue.
     */
    public function recordPlatformFee(string $orderId, int $feeMinor, string $currency): ?string
    {
        if ($feeMinor < 1) {
            return null;
        }

        return $this->post('platform_fee', $orderId, [
            ['account' => self::PLATFORM_SALES, 'direction' => 'debit', 'amount_minor' => $feeMinor, 'currency' => $currency],
            ['account' => self::PLATFORM_FEE, 'direction' => 'credit', 'amount_minor' => $feeMinor, 'currency' => $currency],
        ], 'platform_fee:'.$orderId);
    }

    /**
     * Brings the posted platform fee down to floor(fee * kept / total). Safe to call any number of times.
     */
    public function settlePlatformFee(string $orderId, int $refundedTotalMinor, int $orderTotalMinor): void
    {
        DB::transaction(function () use ($orderId, $refundedTotalMinor, $orderTotalMinor): void {
            $this->lockOrder($orderId);
            $granted = (int) DB::table('orders')->where('id', $orderId)->value('platform_fee_minor');
            if ($granted < 1 || $orderTotalMinor < 1) {
                return;
            }
            $kept = max(0, $orderTotalMinor - $refundedTotalMinor);
            $target = intdiv($granted * $kept, $orderTotalMinor);
            $held = $this->platformFeeHeld($orderId);
            $delta = $held - $target;
            if ($delta < 1) {
                return;
            }
            $currency = (string) (DB::table('orders')->where('id', $orderId)->value('currency') ?: 'USD');
            $this->post('platform_fee_reverse', $orderId, [
                ['account' => self::PLATFORM_FEE, 'direction' => 'debit', 'amount_minor' => $delta, 'currency' => $currency],
                ['account' => self::PLATFORM_SALES, 'direction' => 'credit', 'amount_minor' => $delta, 'currency' => $currency],
            ], 'platform_fee_reverse:'.$orderId.':'.$target);
        });
    }

    // ---------------------------------------------------------------- price protection

    /** Credits a price-protection payout. $cumulativeMinor is the total the order should have received. */
    public function creditPriceProtection(string $orderId, string $buyerId, int $cumulativeMinor, string $currency): ?string
    {
        return DB::transaction(function () use ($orderId, $buyerId, $cumulativeMinor, $currency): ?string {
            $this->lockOrder($orderId);
            $paid = $this->priceProtectionPaid($orderId, $buyerId);
            $delta = $cumulativeMinor - $paid['credited'];
            if ($delta < 1) {
                return null;
            }

            return $this->post('price_protection', $orderId, [
                ['account' => self::PLATFORM_PRICE_PROTECTION, 'direction' => 'debit', 'amount_minor' => $delta, 'currency' => $currency],
                ['account' => self::available($buyerId), 'direction' => 'credit', 'amount_minor' => $delta, 'currency' => $currency],
            ], 'price_protection:'.$orderId.':'.$cumulativeMinor);
        });
    }

    /** Takes back the share of price-protection credit that belongs to the refunded part of the order. */
    public function settlePriceProtection(string $orderId, int $refundedTotalMinor, int $orderTotalMinor): void
    {
        DB::transaction(function () use ($orderId, $refundedTotalMinor, $orderTotalMinor): void {
            $order = $this->lockOrder($orderId);
            if ($order === null) {
                return;
            }
            $buyerId = (string) $order->buyer_id;
            $paid = $this->priceProtectionPaid($orderId, $buyerId);
            if ($paid['credited'] < 1) {
                return;
            }
            $kept = max(0, $orderTotalMinor - $refundedTotalMinor);
            $target = $orderTotalMinor < 1 ? 0 : intdiv($paid['credited'] * $kept, $orderTotalMinor);
            $delta = ($paid['credited'] - $paid['reversed']) - $target;
            if ($delta < 1) {
                return;
            }
            $this->post('price_protection_reverse', $orderId, [
                ['account' => self::available($buyerId), 'direction' => 'debit', 'amount_minor' => $delta, 'currency' => $paid['currency']],
                ['account' => self::PLATFORM_PRICE_PROTECTION, 'direction' => 'credit', 'amount_minor' => $delta, 'currency' => $paid['currency']],
            ], 'price_protection_reverse:'.$orderId.':'.$target);
        });
    }

    // ---------------------------------------------------------------- accounts

    public static function pending(string $userId): string
    {
        return 'user:'.$userId.':pending';
    }

    public static function available(string $userId): string
    {
        return 'user:'.$userId.':available';
    }

    /**
     * What each creator was granted, released, and had reversed on one order.
     *
     * @return array<string, array{granted: int, released: int, reversed_pending: int, reversed_available: int, currency: string}>
     */
    public function commissionPositions(string $orderId): array
    {
        $rows = DB::table('ledger_lines as l')
            ->join('ledger_journals as j', 'j.id', '=', 'l.journal_id')
            ->where('j.order_id', $orderId)
            ->whereIn('j.kind', ['commission_pending', 'commission_release', 'commission_reverse'])
            ->where('l.account', 'like', 'user:%')
            ->groupBy('j.kind', 'l.account', 'l.direction', 'l.currency')
            ->get(['j.kind', 'l.account', 'l.direction', 'l.currency', DB::raw('SUM(l.amount_minor) AS amount')]);

        $positions = [];
        foreach ($rows as $row) {
            // The account CHECK guarantees user:{36-char uuid}:{pending|available}.
            $userId = substr((string) $row->account, 5, 36);
            $bucket = substr((string) $row->account, 42);
            $positions[$userId] ??= ['granted' => 0, 'released' => 0, 'reversed_pending' => 0, 'reversed_available' => 0, 'currency' => (string) $row->currency];
            $amount = (int) $row->amount;
            match (true) {
                $row->kind === 'commission_pending' && $bucket === 'pending' && $row->direction === 'credit' => $positions[$userId]['granted'] += $amount,
                $row->kind === 'commission_release' && $bucket === 'available' && $row->direction === 'credit' => $positions[$userId]['released'] += $amount,
                $row->kind === 'commission_reverse' && $bucket === 'pending' && $row->direction === 'debit' => $positions[$userId]['reversed_pending'] += $amount,
                $row->kind === 'commission_reverse' && $bucket === 'available' && $row->direction === 'debit' => $positions[$userId]['reversed_available'] += $amount,
                default => null,
            };
        }
        ksort($positions);

        return $positions;
    }

    private function platformFeeHeld(string $orderId): int
    {
        $rows = DB::table('ledger_lines as l')
            ->join('ledger_journals as j', 'j.id', '=', 'l.journal_id')
            ->where('j.order_id', $orderId)
            ->whereIn('j.kind', ['platform_fee', 'platform_fee_reverse'])
            ->where('l.account', self::PLATFORM_FEE)
            ->groupBy('l.direction')
            ->get(['l.direction', DB::raw('SUM(l.amount_minor) AS amount')]);
        $held = 0;
        foreach ($rows as $row) {
            $held += $row->direction === 'credit' ? (int) $row->amount : -(int) $row->amount;
        }

        return $held;
    }

    /** @return array{credited: int, reversed: int, currency: string} */
    private function priceProtectionPaid(string $orderId, string $buyerId): array
    {
        $rows = DB::table('ledger_lines as l')
            ->join('ledger_journals as j', 'j.id', '=', 'l.journal_id')
            ->where('j.order_id', $orderId)
            ->whereIn('j.kind', ['price_protection', 'price_protection_reverse'])
            ->where('l.account', self::available($buyerId))
            ->groupBy('j.kind', 'l.currency')
            ->get(['j.kind', 'l.currency', DB::raw('SUM(l.amount_minor) AS amount')]);
        $paid = ['credited' => 0, 'reversed' => 0, 'currency' => 'USD'];
        foreach ($rows as $row) {
            $paid['currency'] = (string) $row->currency;
            if ($row->kind === 'price_protection') {
                $paid['credited'] += (int) $row->amount;
            } else {
                $paid['reversed'] += (int) $row->amount;
            }
        }

        return $paid;
    }

    private function releasable(?object $order): bool
    {
        if ($order === null || $order->status !== 'paid' || $order->delivered_at === null) {
            return false;
        }
        if (! in_array($order->payment_status, ['captured', 'partially_refunded'], true)) {
            return false;
        }

        return now()->greaterThan(now()->parse($order->delivered_at)->addDays(self::RELEASE_AFTER_DAYS));
    }

    /** Serializes ledger work on one order across processes; returns the order row. */
    private function lockOrder(string $orderId): ?object
    {
        DB::selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['ledger:order:'.$orderId]);

        return DB::table('orders')->where('id', $orderId)->first();
    }

    private function grantKeptPurchase(string $orderId): void
    {
        $order = DB::table('orders')->where('id', $orderId)->first(['buyer_id', 'status']);
        if ($order === null || $order->status !== 'paid') {
            return;
        }
        $buyer = User::query()->find($order->buyer_id);
        if ($buyer !== null) {
            $this->rewards->grant($buyer, 'purchase', $orderId, 25, 20);
        }
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function validate(string $kind, ?string $orderId, array $lines, string $idempotencyKey): void
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException("Unknown ledger journal kind [{$kind}].");
        }
        if ($orderId !== null && ! Str::isUuid($orderId)) {
            throw new InvalidArgumentException('Ledger order id must be a UUID.');
        }
        if (preg_match(self::KEY_PATTERN, $idempotencyKey) !== 1) {
            throw new InvalidArgumentException('Ledger idempotency key must be 8-200 safe characters.');
        }
        if (count($lines) < 2) {
            throw new InvalidArgumentException('A ledger journal needs at least two lines.');
        }
        $sums = [];
        foreach ($lines as $line) {
            $account = $line['account'] ?? null;
            $direction = $line['direction'] ?? null;
            $amount = $line['amount_minor'] ?? null;
            $currency = $line['currency'] ?? null;
            if (! is_string($account) || preg_match(self::ACCOUNT_PATTERN, $account) !== 1) {
                throw new InvalidArgumentException('Invalid ledger account.');
            }
            if ($direction !== 'debit' && $direction !== 'credit') {
                throw new InvalidArgumentException('Ledger direction must be debit or credit.');
            }
            if (! is_int($amount) || $amount < 1 || $amount > self::MAX_AMOUNT_MINOR) {
                throw new InvalidArgumentException('Ledger amounts are positive integer minor units.');
            }
            if (! is_string($currency) || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
                throw new InvalidArgumentException('Ledger currency must be an ISO 4217 code.');
            }
            $sums[$currency] = ($sums[$currency] ?? 0) + ($direction === 'debit' ? $amount : -$amount);
        }
        foreach ($sums as $currency => $sum) {
            if ($sum !== 0) {
                throw new InvalidArgumentException("Ledger journal is not balanced in {$currency}.");
            }
        }
    }
}

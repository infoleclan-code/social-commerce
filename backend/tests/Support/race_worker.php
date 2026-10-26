<?php

declare(strict_types=1);

/**
 * One contender in a concurrency test. Boots the real application (testing environment, committed
 * data), waits at a barrier until the parent releases every contender at once, runs one scenario step
 * and prints a JSON line with the outcome. Never prints credentials.
 *
 *   php tests/Support/race_worker.php <config.json> <worker-index>
 */

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\Commerce\CheckoutService;
use App\Services\Commerce\LedgerService;
use App\Services\Commerce\PriceGuard;
use App\Services\Commerce\ScanAndTapService;
use App\Services\Payments\FakePaymentGateway;
use App\Services\Payments\OrderPayments;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\StripeWebhookHandler;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$config = json_decode((string) file_get_contents($argv[1]), true, 16, JSON_THROW_ON_ERROR);
$index = (int) $argv[2];
foreach ($config['env'] as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Barrier: the parent holds an exclusive advisory lock until every worker is connected and waiting.
DB::selectOne('SELECT pg_advisory_lock_shared(?)', [(int) $config['barrier']]);
DB::selectOne('SELECT pg_advisory_unlock_shared(?)', [(int) $config['barrier']]);

$result = ['worker' => $index, 'ok' => false];
try {
    $result = array_merge($result, match ($config['scenario']) {
        'one_tap' => (function () use ($config, $index): array {
            $buyer = User::query()->findOrFail($config['buyers'][$index % count($config['buyers'])]);
            $response = app(ScanAndTapService::class)->oneTap($buyer, $config['product'], 1, $config['keys'][$index]);

            return ['ok' => true, 'order' => $response['order']['id'], 'replayed' => $response['replayed'], 'payment' => $response['order']['payment_status']];
        })(),
        'release_pending' => (function () use ($config, $index): array {
            $journal = app(LedgerService::class)->post('commission_release', $config['order'], [
                ['account' => 'user:'.$config['creator'].':pending', 'direction' => 'debit', 'amount_minor' => $config['amount'], 'currency' => 'USD'],
                ['account' => 'user:'.$config['creator'].':available', 'direction' => 'credit', 'amount_minor' => $config['amount'], 'currency' => 'USD'],
            ], $config['keys'][$index]);

            return ['ok' => true, 'journal' => $journal];
        })(),
        'release_or_refund' => (function () use ($config, $index): array {
            $ledger = app(LedgerService::class);
            $index % 2 === 0 ? $ledger->releaseKept($config['order']) : $ledger->settleCommissions($config['order'], $config['total'], $config['total']);

            return ['ok' => true];
        })(),
        'checkout_squad' => (function () use ($config, $index): array {
            $user = User::query()->findOrFail($config['buyers'][$index]);
            $order = app(CheckoutService::class)->checkout($user, $config['product'], null, $config['squad'], null, 1, $config['keys'][$index]);

            return ['ok' => true, 'order' => $order['id']];
        })(),
        'webhook' => (function () use ($config): array {
            return ['ok' => true, 'outcome' => app(StripeWebhookHandler::class)->handle($config['event'])];
        })(),
        'capture_due' => (function () use ($config): array {
            /** @var FakePaymentGateway $stripe */
            $stripe = app(PaymentGateway::class);
            foreach ($config['intents'] as $intent) {
                $stripe->seedIntent($intent['id'], $intent['amount'], 'USD', 'requires_capture', ['order_id' => $intent['order']]);
            }
            $claimed = app(OrderPayments::class)->captureDue(3);
            $captures = count(array_filter($stripe->calls, fn (array $call): bool => $call['method'] === 'capture'));

            return ['ok' => true, 'claimed' => $claimed, 'captures' => $captures];
        })(),
        'price_sweep' => (function (): array {
            return ['ok' => true, 'credited' => app(PriceGuard::class)->sweep()];
        })(),
        default => throw new RuntimeException('Unknown scenario'),
    });
} catch (ApiException $refused) {
    $result['code'] = $refused->errorCode;
} catch (QueryException $database) {
    $result['code'] = 'SQLSTATE_'.($database->errorInfo[0] ?? 'unknown');
} catch (Throwable $failure) {
    $result['code'] = 'ERROR_'.$failure::class;
    $result['message'] = substr($failure->getMessage(), 0, 200);
}

echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;

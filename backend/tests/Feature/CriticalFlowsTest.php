<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Commerce\CatalogImageIndexer;
use App\Services\Commerce\GrowthService;
use App\Services\Commerce\LandedCost;
use App\Services\Commerce\LedgerService;
use App\Services\Media\VisualEmbedding;
use App\Services\Media\VisualSignature;
use App\Services\Payments\AuthorizeRequest;
use App\Services\Payments\StripePaymentGateway;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\Support\BuildsCommerce;
use Tests\Support\BuildsSocial;
use Tests\TestCase;
use Throwable;

final class CriticalFlowsTest extends TestCase
{
    use BuildsCommerce;
    use BuildsSocial;
    use RefreshDatabase;

    #[Group('purchases')]
    public function test_webhook_rejects_a_bad_signature_and_a_refund_reverses_once(): void
    {
        $buyer = $this->makeUser();
        $creator = $this->makeUser();
        $product = $this->pricedProduct(10_000, 500);
        $order = $this->capturedOrder($buyer, $product, 10_000, ['creator_id' => $creator->id]);
        $ledger = app(LedgerService::class);
        $ledger->pendCommission($creator->id, $order, 800, 'USD');
        $before = (int) DB::table('products')->where('id', $product)->value('stock');

        config(['services.stripe.webhook_secret' => self::WEBHOOK_SECRET]);
        $this->call('POST', '/api/v1/stripe/webhook', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => 't=1,v1=deadbeef',
            'CONTENT_TYPE' => 'application/json',
        ], '{"id":"evt_bad"}')->assertStatus(400);

        // A full refund issued from the Stripe dashboard reaches us only as charge.refunded.
        $intent = (string) DB::table('orders')->where('id', $order)->value('stripe_payment_intent_id');
        $this->stripe()->refund($intent, 10_000, 'USD', 'dashboard-refund-1', 'requested_by_customer');
        $event = $this->stripe()->event('charge.refunded', $intent);
        $this->sendWebhook($event)->assertOk()->assertJsonPath('outcome', 'applied');
        $this->sendWebhook($event)->assertOk()->assertJsonPath('outcome', 'duplicate');

        $this->assertSame(0, $ledger->balance('user:'.$creator->id.':pending'));
        $this->assertSame(0, $ledger->balance('user:'.$creator->id.':available'));
        $this->assertSame('cancelled', DB::table('orders')->where('id', $order)->value('status'));
        $this->assertSame('refunded', DB::table('orders')->where('id', $order)->value('payment_status'));
        $this->assertSame($before + 1, (int) DB::table('products')->where('id', $product)->value('stock'));
        $this->assertSame(1, DB::table('ledger_journals')->where('order_id', $order)->where('kind', 'commission_reverse')->count());
        $this->assertSame(1, DB::table('stripe_events')->where('id', $event['id'])->count());
    }

    #[Group('purchases')]
    public function test_a_captured_intent_is_not_captured_again_by_the_webhook(): void
    {
        $buyer = $this->makeUser();
        $product = $this->pricedProduct(4_000, 0);
        $order = $this->capturedOrder($buyer, $product, 4_000);
        $intent = (string) DB::table('orders')->where('id', $order)->value('stripe_payment_intent_id');
        $journals = DB::table('ledger_journals')->count();

        $this->sendWebhook($this->stripe()->event('payment_intent.succeeded', $intent))->assertOk();

        $this->assertSame('paid', DB::table('orders')->where('id', $order)->value('status'));
        $this->assertSame([], array_filter($this->stripe()->calls, fn (array $call): bool => $call['method'] === 'capture'));
        $this->assertSame($journals, DB::table('ledger_journals')->count());
    }

    #[Group('purchases')]
    public function test_payment_intent_is_manual_capture_and_the_same_key_authorizes_once(): void
    {
        $params = (new StripePaymentGateway(''))->paymentIntentParams(new AuthorizeRequest('cus_1', 'pm_1', 2500, 'USD', 'order:x:authorize', ['order_id' => 'x']));
        $this->assertSame('manual', $params['capture_method']);
        $this->assertSame(['card'], $params['payment_method_types']);
        $this->assertTrue($params['confirm']);
        $this->assertSame('x', $params['metadata']['order_id']);

        $buyer = $this->makeUser();
        $product = $this->pricedProduct(2500, 0);
        $this->savedCard($buyer);
        Sanctum::actingAs($buyer);
        $headers = ['Idempotency-Key' => 'onetap-once-key'];
        $first = $this->postJson('/api/v1/checkout/one-tap', ['product_id' => $product, 'quantity' => 1], $headers)->assertCreated();
        $second = $this->postJson('/api/v1/checkout/one-tap', ['product_id' => $product, 'quantity' => 1], $headers)->assertCreated();
        $second->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($first->json('data.order.id'), $second->json('data.order.id'));

        $this->assertCount(1, $this->stripe()->authorizations);
        $this->assertSame('manual', $this->stripe()->authorizations[0]['capture_method']);
        $this->assertSame(1, DB::table('orders')->where('idempotency_key', 'onetap-once-key')->count());
        $this->assertSame(4, (int) DB::table('products')->where('id', $product)->value('stock'));
    }

    #[Group('purchases')]
    public function test_commission_stays_pending_until_the_window_closes_and_withdrawal_reverses_it(): void
    {
        $buyer = $this->makeUser();
        $creator = $this->makeUser();
        $product = $this->pricedProduct(8_000, 0);
        $order = $this->capturedOrder($buyer, $product, 8_000, ['creator_id' => $creator->id, 'created_at' => now()->subDays(3)]);
        $ledger = app(LedgerService::class);
        $ledger->pendCommission($creator->id, $order, 640, 'USD');
        $ledger->releaseKept($order);
        $this->assertSame(640, $ledger->balance('user:'.$creator->id.':pending'));
        $this->assertSame(0, $ledger->balance('user:'.$creator->id.':available'));

        app(GrowthService::class)->withdraw($buyer, $order);
        $this->assertSame(0, $ledger->balance('user:'.$creator->id.':pending'));
        $this->assertSame(0, $ledger->balance('user:'.$creator->id.':available'));
        $this->assertSame('cancelled', DB::table('orders')->where('id', $order)->value('status'));
        $this->assertSame(8_000, (int) DB::table('orders')->where('id', $order)->value('amount_refunded_minor'));
        $this->assertCount(1, $this->stripe()->refunds);
    }

    #[Group('purchases')]
    public function test_an_unbalanced_journal_cannot_commit(): void
    {
        $race = $this->raceConnection();
        $journal = (string) Str::uuid7();
        $race->beginTransaction();
        try {
            $race->table('ledger_journals')->insert([
                'id' => $journal,
                'kind' => 'sale',
                'order_id' => null,
                'idempotency_key' => 'test:unbalanced:'.$journal,
                'created_at' => now(),
            ]);
            $race->table('ledger_lines')->insert([
                'id' => (string) Str::uuid7(),
                'journal_id' => $journal,
                'account' => 'platform:unbalanced',
                'direction' => 'debit',
                'amount_minor' => 100,
                'currency' => 'USD',
                'created_at' => now(),
            ]);
            $race->commit();
            $this->fail('An unbalanced journal committed.');
        } catch (Throwable $exception) {
            $this->assertStringContainsString('not balanced', $exception->getMessage());
            try {
                $race->rollBack();
            } catch (Throwable) {
            }
        }
        $this->assertSame(0, $race->table('ledger_journals')->where('id', $journal)->count());
    }

    #[Group('purchases')]
    public function test_two_concurrent_inserts_cannot_create_two_charges(): void
    {
        $race = $this->raceConnection();
        $buyer = (string) Str::uuid7();
        $product = (string) Str::uuid7();
        $race->table('users')->insert([
            'id' => $buyer,
            'email' => 'race-'.$buyer.'@example.com',
            'username' => 'r'.substr(str_replace('-', '', $buyer), 0, 12),
            'display_name' => 'Race',
            'social_feed_enabled' => true,
        ]);
        $race->table('products')->insert([
            'id' => $product,
            'title' => 'Race piece',
            'brand' => 'Test',
            'image_url' => 'https://example.com/r.jpg',
            'price_minor' => 1000,
            'squad_price_minor' => 700,
            'slug' => 'race-'.str_replace('-', '', $product),
            'currency' => 'USD',
            'stock' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $file = tempnam(sys_get_temp_dir(), 'charge');
        $pg = config('database.connections.pgsql');
        file_put_contents($file, json_encode([
            'dsn' => 'pgsql:host='.$pg['host'].';port='.$pg['port'].';dbname='.$pg['database'],
            'user' => $pg['username'],
            'password' => $pg['password'],
            'buyer' => $buyer,
            'product' => $product,
            'key' => 'race-'.$buyer,
            'order_a' => (string) Str::uuid7(),
            'order_b' => (string) Str::uuid7(),
        ], JSON_THROW_ON_ERROR));
        chmod($file, 0600);

        try {
            $script = base_path('tests/Support/insert_order_once.php');
            $left = new Process([PHP_BINARY, $script, $file, 'a'], base_path());
            $right = new Process([PHP_BINARY, $script, $file, 'b'], base_path());
            $left->start();
            $right->start();
            $left->wait();
            $right->wait();
            $lines = [trim($left->getOutput()), trim($right->getOutput())];
            sort($lines);
            $this->assertSame(['dup', 'ok'], $lines);
            $this->assertSame(1, $race->table('orders')->where('idempotency_key', 'race-'.$buyer)->count());
        } finally {
            @unlink($file);
            $race->table('orders')->where('buyer_id', $buyer)->delete();
            $race->table('products')->where('id', $product)->delete();
            $race->table('users')->where('id', $buyer)->delete();
        }
    }

    #[Group('purchases')]
    public function test_two_concurrent_journals_both_balance(): void
    {
        $account = 'platform:race-'.substr(str_replace('-', '', (string) Str::uuid7()), 0, 12);
        $file = tempnam(sys_get_temp_dir(), 'journ');
        $pg = config('database.connections.pgsql');
        $journalA = (string) Str::uuid7();
        $journalB = (string) Str::uuid7();
        file_put_contents($file, json_encode([
            'dsn' => 'pgsql:host='.$pg['host'].';port='.$pg['port'].';dbname='.$pg['database'],
            'user' => $pg['username'],
            'password' => $pg['password'],
            'account' => $account,
            'journal_a' => $journalA,
            'journal_b' => $journalB,
            'journal_a_debit' => (string) Str::uuid7(),
            'journal_a_credit' => (string) Str::uuid7(),
            'journal_b_debit' => (string) Str::uuid7(),
            'journal_b_credit' => (string) Str::uuid7(),
        ], JSON_THROW_ON_ERROR));
        chmod($file, 0600);

        try {
            $script = base_path('tests/Support/insert_balanced_journal.php');
            $left = new Process([PHP_BINARY, $script, $file, 'a'], base_path());
            $right = new Process([PHP_BINARY, $script, $file, 'b'], base_path());
            $left->start();
            $right->start();
            $left->wait();
            $right->wait();
            $this->assertSame('ok', trim($left->getOutput()));
            $this->assertSame('ok', trim($right->getOutput()));
            $race = $this->raceConnection();
            $this->assertSame(200, (int) $race->table('ledger_lines')->where('account', $account)->sum('amount_minor'));
            $this->assertSame(200, (int) $race->table('ledger_lines')->where('account', 'platform:sale')->whereIn('journal_id', [$journalA, $journalB])->sum('amount_minor'));
        } finally {
            @unlink($file);
        }
    }

    public function test_scan_returns_the_three_cheapest_verified_offers_and_drops_mismatches(): void
    {
        $image = $this->reelImage(9);
        $same = ['canonical_key' => 'gtin:4006381333931'];
        $offers = [
            $this->offer($image, 8_000, 5_000, 'Expensive ship', $same),
            $this->offer($image, 9_000, 200, 'Low ship', $same),
            $this->offer($image, 10_000, 100, 'Mid ship', $same),
            $this->offer($image, 7_000, 400, 'Best landed', $same),
        ];
        // Same light layout, different shapes: luma agrees, structure does not.
        $lookalike = $this->offer(null, 1_000, 0, 'Lookalike');
        app(CatalogImageIndexer::class)->indexSignature($lookalike, $this->lookalikeOf(VisualEmbedding::fromBytes($image)));
        $viewer = $this->makeUser(['country_code' => 'DE']);
        Sanctum::actingAs($viewer);
        $canonicalProduct = $this->pricedProduct(50_000, 0);
        $author = $this->makeUser();
        $post = (string) Str::uuid7();
        DB::table('feed_posts')->insert([
            'id' => $post,
            'user_id' => $author->id,
            'kind' => 'media',
            'audience' => 'public',
            'media_type' => 'image',
            'media_path' => 'posts/'.$post.'.jpg',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('reel_product_tags')->insert([
            'post_id' => $post,
            'product_id' => $canonicalProduct,
            'created_at' => now(),
        ]);

        $response = $this->post('/api/v1/visual-search', [
            'image_base64' => base64_encode($image),
            'image_sha256' => hash('sha256', $image),
        ], ['Accept' => 'application/json'])->assertOk();
        $ids = $response->json('data.results.*.product.id');
        $this->assertCount(3, $ids);
        $this->assertNotContains($offers[0], $ids);
        $this->assertNotContains($lookalike, $ids);
        $this->assertNotContains($canonicalProduct, $ids);
        $totals = array_map(fn (string $id): int => LandedCost::quote(
            DB::table('products')->where('id', $id)->first(),
            'DE',
        )['total']['amount_minor'], $ids);
        $sorted = $totals;
        sort($sorted);
        $this->assertSame($sorted, $totals);
        $this->assertTrue($response->json('data.results.0.verified'));
        $this->assertSame('cross_checked', $response->json('data.results.0.match'));
        $this->assertSame(DB::table('products')->where('id', $ids[0])->value('seller_id'), $response->json('data.results.0.product.merchant.id'));

        $empty = $this->post('/api/v1/visual-search', [
            'image_base64' => base64_encode($this->reelImage(2)),
            'post_id' => $post,
        ], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame('canonical', $empty->json('data.fallback'));
        $this->assertSame([$canonicalProduct], $empty->json('data.results.*.product.id'));
        $this->assertFalse($empty->json('data.results.0.verified'));
    }

    private function raceConnection(): Connection
    {
        $base = config('database.connections.pgsql');
        config(['database.connections.ledger_race' => $base]);
        DB::purge('ledger_race');

        return DB::connection('ledger_race');
    }

    private function pricedProduct(int $price, int $shipping): string
    {
        return $this->offer(null, $price, $shipping, 'Piece');
    }

    /** @param  array<string, mixed>  $extra */
    private function offer(?string $image, int $price, int $shipping, string $title, array $extra = []): string
    {
        $id = (string) Str::uuid7();
        DB::table('products')->insert($extra + [
            'id' => $id,
            'title' => $title.' '.substr($id, 0, 8),
            'brand' => 'Test',
            'image_url' => 'https://example.com/p.jpg',
            'price_minor' => $price,
            'squad_price_minor' => intdiv($price * 70, 100),
            'slug' => 'p-'.str_replace('-', '', $id),
            'currency' => 'USD',
            'stock' => 5,
            'ships_from' => 'US',
            'shipping_minor' => $shipping,
            'vat_bps' => 2000,
            'duty_bps' => 500,
            'handling_days' => 2,
            'seller_id' => $this->verifiedSeller()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        if ($image !== null) {
            $this->assertSame('indexed', app(CatalogImageIndexer::class)->indexBytes($id, $image));
        }

        return $id;
    }

    /** Same luma, an unrelated structure and hash: passes one check, fails the other. */
    private function lookalikeOf(VisualSignature $base): VisualSignature
    {
        $weakest = array_keys($base->structure, min($base->structure))[0];
        $structure = array_fill(0, 64, 0.0);
        $structure[$weakest] = 1.0;

        return new VisualSignature($base->luma, $structure, strtr($base->dhash, '01', '10'), hash('sha256', 'lookalike'.$base->sha256));
    }
}

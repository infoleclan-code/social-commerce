<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Commerce\CatalogImageIndexer;
use App\Services\Commerce\CheckoutService;
use App\Services\Commerce\HabitScaleService;
use App\Services\Commerce\LedgerService;
use App\Services\Media\VisualEmbedding;
use App\Services\Media\VisualSignature;
use App\Services\Payments\FakePaymentGateway;
use App\Services\Payments\PaymentGateway;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsCommerce;
use Tests\Support\BuildsSocial;
use Tests\TestCase;

final class PlatformP0Test extends TestCase
{
    use BuildsCommerce;
    use BuildsSocial;
    use RefreshDatabase;

    public function test_wallet_cash_cannot_be_edited_outside_the_ledger(): void
    {
        $user = $this->makeUser();
        DB::table('user_wallets')->insert([
            'user_id' => $user->id,
            'coins' => 0,
            'xp' => 0,
            'cash_minor' => 0,
            'currency' => 'USD',
            'updated_at' => now(),
        ]);

        try {
            DB::transaction(fn () => DB::table('user_wallets')->where('user_id', $user->id)->update(['cash_minor' => 500]));
            $this->fail('Wallet cash was edited outside the ledger.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('posted only by the ledger', $exception->getMessage());
        }
        $other = $this->makeUser();
        $this->expectException(QueryException::class);
        DB::table('user_wallets')->insert(['user_id' => $other->id, 'cash_minor' => 500, 'currency' => 'USD', 'updated_at' => now()]);
    }

    public function test_commission_stays_pending_until_the_return_window_closes_and_reverses_on_withdrawal(): void
    {
        $buyer = $this->makeUser();
        $creator = $this->makeUser();
        $product = $this->product(12_800);
        $orderId = $this->capturedOrder($buyer, $product, 12_800, ['creator_id' => $creator->id, 'created_at' => now()->subDays(15)]);

        $ledger = app(LedgerService::class);
        $ledger->pendCommission($creator->id, $orderId, 1_024, 'USD');
        $this->assertSame(1_024, $ledger->balance('user:'.$creator->id.':pending'));
        $this->assertSame(0, $ledger->balance('user:'.$creator->id.':available'));

        $ledger->releaseKept($orderId);
        $this->assertSame(0, $ledger->balance('user:'.$creator->id.':pending'));
        $this->assertSame(1_024, (int) DB::table('user_wallets')->where('user_id', $creator->id)->value('cash_minor'));

        $ledger->reverseOrder($orderId);
        $this->assertSame(0, (int) DB::table('user_wallets')->where('user_id', $creator->id)->value('cash_minor'));
        $this->assertSame(0, $ledger->balance('user:'.$creator->id.':available'));
    }

    public function test_visual_search_ranks_by_cosine_similarity(): void
    {
        $image = $this->reelImage(4);
        $near = $this->indexedProduct($image, 1000, ['title' => 'Near jacket']);
        $base = VisualEmbedding::fromBytes($image);
        $far = $this->catalogProduct(1000, ['title' => 'Far coat', 'seller_id' => $this->verifiedSeller()->id]);
        $weakest = array_keys($base->structure, min($base->structure))[0];
        $structure = array_fill(0, 64, 0.0);
        $structure[$weakest] = 1.0;
        app(CatalogImageIndexer::class)->indexSignature($far, new VisualSignature($base->luma, $structure, strtr($base->dhash, '01', '10'), hash('sha256', 'far')));
        $unembedded = $this->catalogProduct(1000, ['title' => 'Unrelated scarf']);

        $viewer = $this->makeUser();
        Sanctum::actingAs($viewer);
        $response = $this->post('/api/v1/visual-search', [
            'image_base64' => base64_encode($image),
        ], ['Accept' => 'application/json']);

        $response->assertOk();
        $this->assertSame('vision_crosscheck_v2', $response->json('data.analyzer'));
        $this->assertSame($near, $response->json('data.results.0.product.id'));
        $this->assertTrue($response->json('data.results.0.verified'));
        $ids = $response->json('data.results.*.product.id');
        $this->assertNotContains($far, $ids);
        $this->assertNotContains($unembedded, $ids);
    }

    public function test_one_tap_authorizes_with_manual_capture_and_a_saved_card(): void
    {
        $buyer = $this->makeUser();
        $product = $this->product(6400);
        DB::table('saved_payment_methods')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $buyer->id,
            'stripe_payment_method_id' => 'pm_test',
            'brand' => 'visa',
            'last4' => '4242',
            'exp_month' => 12,
            'exp_year' => 2030,
            'is_default' => true,
            'created_at' => now(),
        ]);
        Sanctum::actingAs($buyer);

        $method = $this->getJson('/api/v1/payment-methods/default')->assertOk()->json('data');
        $this->assertTrue($method['has_default']);
        $this->assertSame('4242', $method['summary']['last4']);
        $this->assertNotSame('preview', $method['summary']['brand']);

        $this->postJson('/api/v1/checkout/one-tap', [
            'product_id' => $product,
            'quantity' => 1,
        ], ['Idempotency-Key' => 'onetap-p0-test'])->assertCreated()
            ->assertJsonPath('data.order.payment_status', 'authorized')
            ->assertJsonPath('data.next_action', null);

        /** @var FakePaymentGateway $gateway */
        $gateway = app(PaymentGateway::class);
        $this->assertSame('manual', $gateway->authorizations[0]['capture_method']);
    }

    public function test_blind_box_requires_age_assurance_and_checkout_does_not_publish_the_purchase(): void
    {
        $buyer = $this->makeUser();
        $this->savedCard($buyer);
        Sanctum::actingAs($buyer);
        $box = DB::table('blind_boxes')->value('id');
        if ($box === null) {
            $product = $this->product(2500);
            $box = (string) Str::uuid7();
            DB::table('blind_boxes')->insert(['id' => $box, 'title' => 'Box', 'price_minor' => 2500, 'currency' => 'USD', 'created_at' => now()]);
            DB::table('blind_box_odds')->insert(['box_id' => $box, 'product_id' => $product, 'weight_bps' => 10000]);
        }

        $this->postJson('/api/v1/blind-boxes/'.$box.'/pull', [], $this->key('pull'))->assertStatus(422)->assertJsonPath('code', 'AGE_ASSURANCE_REQUIRED');
        $this->postJson('/api/v1/trust/age', ['confirmed' => true])->assertOk();
        $this->postJson('/api/v1/blind-boxes/'.$box.'/pull', [], $this->key('pull'))->assertCreated()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.payment_status', 'captured');

        $product = $this->product(900);
        $order = app(CheckoutService::class)->checkout($buyer, $product, null, null, null, 1, 'share-off-'.Str::random(8), null, false);
        $this->assertFalse($order['share_publicly']);
        $this->assertSame('authorized', $order['payment_status']);
        $this->expireUndoWindow($order['id']);
        $this->captureDue();
        $this->assertSame('paid', DB::table('orders')->where('id', $order['id'])->value('status'));
        $this->assertSame(0, DB::table('activity_events')->where('product_id', $product)->count());

        app(HabitScaleService::class);
    }

    public function test_withdrawal_uses_fourteen_days_from_delivery(): void
    {
        $buyer = $this->makeUser();
        $product = $this->product(1000);
        $open = $this->capturedOrder($buyer, $product, 1000, ['created_at' => now()->subDays(3)]);
        $closed = $this->capturedOrder($buyer, $product, 1000, ['created_at' => now()->subDays(20)]);
        Sanctum::actingAs($buyer);
        $this->postJson('/api/v1/orders/'.$closed.'/withdraw', [], $this->key('wd'))->assertStatus(409);
        $this->postJson('/api/v1/orders/'.$open.'/withdraw', [], $this->key('wd'))->assertOk();
        $this->assertSame('cancelled', DB::table('orders')->where('id', $open)->value('status'));
        $this->assertSame('refunded', DB::table('orders')->where('id', $open)->value('payment_status'));
        $this->assertSame('paid', DB::table('orders')->where('id', $closed)->value('status'));
    }

    private function product(int $price, string $title = 'Piece'): string
    {
        $id = (string) Str::uuid7();
        DB::table('products')->insert([
            'id' => $id,
            'title' => $title.' '.substr($id, 0, 8),
            'brand' => 'Test',
            'image_url' => 'https://example.com/p.jpg',
            'price_minor' => $price,
            'squad_price_minor' => intdiv($price * 70, 100),
            'slug' => 'p-'.str_replace('-', '', $id),
            'currency' => 'USD',
            'stock' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function solidJpeg(int $red, int $green, int $blue): string
    {
        $image = imagecreatetruecolor(32, 16);
        imagefill($image, 0, 0, imagecolorallocate($image, $red, $green, $blue));
        ob_start();
        imagejpeg($image, null, 90);

        return (string) ob_get_clean();
    }
}

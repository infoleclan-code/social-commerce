<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Services\Commerce\LedgerService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsCommerce;
use Tests\Support\BuildsSocial;
use Tests\TestCase;

/**
 * Squads, gifts, blind boxes, withdrawals and saved cards: every one is a real card payment now, and
 * none of them can produce a "paid" order without a captured PaymentIntent.
 */
final class PurchasePathsTest extends TestCase
{
    use BuildsCommerce;
    use BuildsSocial;
    use RefreshDatabase;

    public function test_a_squad_holds_the_solo_price_and_captures_the_squad_price_once_a_second_member_joins(): void
    {
        [$host, $friend] = [$this->makeUser(), $this->makeUser()];
        $this->savedCard($host);
        $this->savedCard($friend);
        $product = $this->catalogProduct(10_000);

        Sanctum::actingAs($host);
        $hosted = $this->postJson('/api/v1/squads/authorize', ['product_id' => $product], $this->key('host'))->assertCreated()
            ->assertJsonPath('data.payment_status', 'authorized')
            ->assertJsonPath('data.authorized.amount_minor', 10_000);
        $squad = (string) $hosted->json('data.squad_id');
        $this->assertSame(0, $this->captureDue(), 'Alone, the hold waits for the squad to close.');

        Sanctum::actingAs($friend);
        $this->postJson('/api/v1/squads/'.$squad.'/authorize-join', [], $this->key('join'))->assertCreated();
        $this->assertSame(2, $this->captureDue());

        $orders = DB::table('orders')->where('squad_id', $squad)->get();
        $this->assertCount(2, $orders);
        foreach ($orders as $order) {
            $this->assertSame(['paid', 'captured', 7_000], [$order->status, $order->payment_status, (int) $order->total_minor]);
        }
        $this->assertSame([7_000, 7_000], array_column(array_values(array_filter($this->stripe()->intents, fn (array $intent): bool => $intent['status'] === 'succeeded')), 'amount_received'));
        $this->assertSame(3, (int) DB::table('products')->where('id', $product)->value('stock'));

        // Settle is members-only and idempotent.
        Sanctum::actingAs($this->makeUser());
        $this->postJson('/api/v1/squads/'.$squad.'/settle')->assertStatus(404)->assertJsonPath('code', 'SQUAD_NOT_FOUND');
        Sanctum::actingAs($friend);
        $this->postJson('/api/v1/squads/'.$squad.'/settle')->assertOk()->assertJsonPath('data.price_kind', 'squad');
        Sanctum::actingAs($friend);
        $this->postJson('/api/v1/squads/'.$squad.'/authorize-join', [], $this->key('again'))->assertStatus(409)->assertJsonPath('code', 'SQUAD_CLOSED');
    }

    public function test_a_lonely_squad_is_captured_at_the_solo_price_when_it_expires(): void
    {
        $host = $this->makeUser();
        $this->savedCard($host);
        Sanctum::actingAs($host);
        $squad = (string) $this->postJson('/api/v1/squads/authorize', ['product_id' => $this->catalogProduct(10_000)], $this->key('solo'))->json('data.squad_id');
        $order = (string) DB::table('orders')->where('squad_id', $squad)->value('id');

        $this->expireUndoWindow($order);
        $this->captureDue();

        $this->assertSame(10_000, (int) DB::table('orders')->where('id', $order)->value('total_minor'));
        $this->assertSame('captured', DB::table('orders')->where('id', $order)->value('payment_status'));
    }

    public function test_a_full_squad_refuses_more_members_even_without_the_service_check(): void
    {
        $host = $this->makeUser();
        $squad = (string) Str::uuid7();
        DB::table('squads')->insert(['id' => $squad, 'product_id' => $this->catalogProduct(), 'host_id' => $host->id, 'discount_bps_per_member' => 500, 'max_members' => 2, 'floor_price_minor' => 500, 'expires_at' => now()->addDay(), 'created_at' => now()]);
        DB::table('squad_members')->insert(['squad_id' => $squad, 'user_id' => $host->id, 'joined_at' => now()]);
        Sanctum::actingAs($this->makeUser());
        $this->postJson('/api/v1/squads/'.$squad.'/join')->assertOk()->assertJsonPath('data.members', 2);
        Sanctum::actingAs($this->makeUser());
        $this->postJson('/api/v1/squads/'.$squad.'/join')->assertStatus(409)->assertJsonPath('code', 'SQUAD_CLOSED');

        $this->expectException(QueryException::class);
        DB::table('squad_members')->insert(['squad_id' => $squad, 'user_id' => $this->makeUser()->id, 'joined_at' => now()]);
    }

    public function test_a_gift_is_claimable_only_once_paid_and_refunded_if_nobody_claims_it(): void
    {
        $buyer = $this->makeUser();
        $this->savedCard($buyer);
        $product = $this->catalogProduct(4_000);
        Sanctum::actingAs($buyer);
        $gift = $this->postJson('/api/v1/gifts', ['product_id' => $product], $this->key('gift'))->assertCreated()
            ->assertJsonPath('status', null)
            ->json('data');
        $this->assertSame('pending', $gift['status']);
        $this->assertSame('authorized', $gift['payment_status']);

        $recipient = $this->makeUser();
        Sanctum::actingAs($recipient);
        $this->getJson('/api/v1/gifts/'.$gift['token'])->assertJsonPath('data.can_claim', false);
        $this->postJson('/api/v1/gifts/'.$gift['token'].'/claim', ['line' => '1 Main St', 'locality' => 'Tbilisi', 'postal_code' => '0100', 'country' => 'GE'])
            ->assertStatus(409)->assertJsonPath('code', 'GIFT_CLOSED');

        $order = (string) DB::table('gifts')->where('token', $gift['token'])->value('order_id');
        $this->assertSame($order, $gift['order_id'], 'The buyer gets the order id to finish 3-D Secure with.');
        $this->expireUndoWindow($order);
        $this->captureDue();
        $this->getJson('/api/v1/gifts/'.$gift['token'])->assertJsonPath('data.can_claim', true);

        DB::table('gifts')->where('token', $gift['token'])->update(['expires_at' => now()->subMinute()]);
        Artisan::call('gifts:refund-expired');
        $this->assertSame('cancelled', DB::table('gifts')->where('token', $gift['token'])->value('status'));
        $this->assertSame(['cancelled', 'refunded'], [DB::table('orders')->where('id', $order)->value('status'), DB::table('orders')->where('id', $order)->value('payment_status')]);
        $this->assertSame(5, (int) DB::table('products')->where('id', $product)->value('stock'));
    }

    public function test_a_blind_box_reveals_nothing_until_paid_and_a_failed_payment_frees_the_day(): void
    {
        $buyer = $this->makeUser();
        DB::table('users')->where('id', $buyer->id)->update(['age_assured_at' => now()]);
        $this->savedCard($buyer);
        $product = $this->catalogProduct(2_500);
        $box = (string) Str::uuid7();
        DB::table('blind_boxes')->insert(['id' => $box, 'title' => 'Mystery', 'price_minor' => 2_000, 'currency' => 'USD', 'created_at' => now()]);
        DB::table('blind_box_odds')->insert(['box_id' => $box, 'product_id' => $product, 'weight_bps' => 10_000]);
        Sanctum::actingAs($buyer);

        $this->stripe()->failNext('authorize', 'requires_action');
        $pending = $this->postJson('/api/v1/blind-boxes/'.$box.'/pull', [], $this->key('box1'))->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.product', null)
            ->assertJsonPath('data.next_action.type', 'use_stripe_sdk');
        $this->postJson('/api/v1/blind-boxes/'.$box.'/pull', [], $this->key('box2'))->assertOk()->assertJsonPath('data.replayed', true)->assertJsonPath('data.product', null);

        $intent = (string) DB::table('orders')->where('id', $pending->json('data.order_id'))->value('stripe_payment_intent_id');
        $this->stripe()->completeAction($intent, false);
        $this->postJson('/api/v1/orders/'.$pending->json('data.order_id').'/sync')->assertJsonPath('data.payment_status', 'failed');
        $this->assertSame('failed', DB::table('blind_pulls')->where('order_id', $pending->json('data.order_id'))->value('status'));

        $this->postJson('/api/v1/blind-boxes/'.$box.'/pull', [], $this->key('box3'))->assertCreated()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.product.id', $product);
        $this->postJson('/api/v1/blind-boxes/'.$box.'/pull', [], $this->key('box4'))->assertOk()->assertJsonPath('data.replayed', true);
        $this->assertSame(1, DB::table('orders')->where('buyer_id', $buyer->id)->where('status', 'paid')->count());
    }

    public function test_withdrawal_voids_a_hold_and_refunds_a_capture_once(): void
    {
        $buyer = $this->makeUser();
        $this->savedCard($buyer);
        $product = $this->catalogProduct(3_000);
        Sanctum::actingAs($buyer);
        $held = (string) $this->postJson('/api/v1/checkout/one-tap', ['product_id' => $product], $this->key('h'))->json('data.order.id');
        $this->expireUndoWindow($held);
        $this->postJson('/api/v1/orders/'.$held.'/withdraw', [], $this->key('w1'))->assertOk();
        $this->assertSame('voided', DB::table('orders')->where('id', $held)->value('payment_status'));

        $kept = $this->capturedOrder($buyer, $product, 3_000, ['created_at' => now()->subDays(2)]);
        $key = $this->key('w2');
        $this->postJson('/api/v1/orders/'.$kept.'/withdraw', [], $key)->assertOk();
        $this->postJson('/api/v1/orders/'.$kept.'/withdraw', [], $key)->assertOk()->assertHeader('Idempotent-Replayed', 'true');
        $this->postJson('/api/v1/orders/'.$kept.'/withdraw', [], $this->key('w3'))->assertStatus(409);

        $this->assertCount(1, $this->stripe()->refunds);
        $this->assertSame(['cancelled', 'refunded', 3_000], [
            DB::table('orders')->where('id', $kept)->value('status'),
            DB::table('orders')->where('id', $kept)->value('payment_status'),
            (int) DB::table('orders')->where('id', $kept)->value('amount_refunded_minor'),
        ]);
        $this->assertSame(0, app(LedgerService::class)->balance(LedgerService::PLATFORM_CARD_CLEARING));
    }

    public function test_cards_are_saved_only_for_their_owner(): void
    {
        $alice = $this->makeUser();
        $mallory = $this->makeUser();
        Sanctum::actingAs($alice);
        $setup = $this->postJson('/api/v1/payment-methods/setup', [], $this->key('s'))->assertOk()->json('data');
        $this->stripe()->completeSetup($setup['id'], 'pm_alice_card', 'mastercard', '5454');
        $this->postJson('/api/v1/payment-methods/confirm', ['setup_intent_id' => $setup['id']], $this->key('c'))->assertOk()
            ->assertJsonPath('data.summary.last4', '5454');
        $this->assertSame(1, $this->countCreateCustomer());
        $this->postJson('/api/v1/payment-methods/setup', [], $this->key('s2'))->assertOk();
        $this->assertSame(1, $this->countCreateCustomer(), 'One Stripe customer per user.');

        Sanctum::actingAs($mallory);
        $this->postJson('/api/v1/payment-methods/setup', [], $this->key('m'))->assertOk();
        $this->postJson('/api/v1/payment-methods/confirm', ['setup_intent_id' => $setup['id']], $this->key('m1'))->assertStatus(422)->assertJsonPath('code', 'PAYMENT_METHOD_INVALID');
        $this->postJson('/api/v1/payment-methods/confirm', ['payment_method_id' => 'pm_alice_card'], $this->key('m2'))->assertStatus(422);
        $this->postJson('/api/v1/payment-methods/confirm', ['payment_method_id' => "pm_x' OR 1=1"], $this->key('m3'))->assertStatus(422);
        $this->postJson('/api/v1/payment-methods/confirm', [], $this->key('m4'))->assertStatus(422);

        $this->assertSame([$alice->id], DB::table('saved_payment_methods')->pluck('user_id')->all());
        $this->assertTrue((bool) DB::table('saved_payment_methods')->where('stripe_payment_method_id', 'pm_alice_card')->value('is_default'));
    }

    public function test_card_confirmation_refuses_every_mismatch(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/payment-methods/confirm', ['setup_intent_id' => 'seti_nothing'], $this->key('n'))->assertStatus(422)->assertJsonPath('code', 'PAYMENT_METHOD_INVALID');

        $setup = $this->postJson('/api/v1/payment-methods/setup', [], $this->key('s'))->json('data');
        $this->postJson('/api/v1/payment-methods/confirm', ['setup_intent_id' => $setup['id']], $this->key('pending'))->assertStatus(422);
        $this->stripe()->completeSetup($setup['id'], 'pm_mine_1');
        $this->postJson('/api/v1/payment-methods/confirm', ['setup_intent_id' => $setup['id'], 'payment_method_id' => 'pm_other_1'], $this->key('mix'))->assertStatus(422);
        $this->postJson('/api/v1/payment-methods/confirm', ['payment_method_id' => 'pm_unknown_9'], $this->key('unknown'))->assertStatus(422);
        $this->postJson('/api/v1/payment-methods/confirm', ['setup_intent_id' => $setup['id']], $this->key('ok'))->assertOk();
        // Re-confirming the same card updates it in place; a card row owned by someone else is never adopted.
        $this->postJson('/api/v1/payment-methods/confirm', ['payment_method_id' => 'pm_mine_1'], $this->key('again'))->assertOk();
        $this->assertSame(1, DB::table('saved_payment_methods')->where('user_id', $user->id)->count());

        // A row the old IDOR bug could have left: another account holding this user's card id.
        DB::table('saved_payment_methods')->insert([
            'id' => (string) Str::uuid7(), 'user_id' => $this->makeUser()->id, 'stripe_payment_method_id' => 'pm_mine_2',
            'brand' => 'visa', 'last4' => '1111', 'is_default' => true, 'created_at' => now(),
        ]);
        $this->stripe()->addPaymentMethod('pm_mine_2', (string) DB::table('users')->where('id', $user->id)->value('stripe_customer_id'));
        $this->postJson('/api/v1/payment-methods/confirm', ['payment_method_id' => 'pm_mine_2'], $this->key('legacy'))->assertStatus(422);
        $this->assertSame(1, DB::table('saved_payment_methods')->where('user_id', $user->id)->count());
        $this->assertTrue((bool) DB::table('saved_payment_methods')->where('stripe_payment_method_id', 'pm_mine_1')->value('is_default'));
    }

    private function countCreateCustomer(): int
    {
        return count(array_unique(array_column(array_filter($this->stripe()->calls, fn (array $call): bool => $call['method'] === 'createCustomer'), 'key')));
    }
}

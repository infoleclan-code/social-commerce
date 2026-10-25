<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Services\Payments\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsCommerce;
use Tests\Support\BuildsSocial;
use Tests\TestCase;

final class CardSetupTest extends TestCase
{
    use BuildsCommerce;
    use BuildsSocial;
    use RefreshDatabase;

    public function test_a_setup_intent_can_be_saved_listed_and_removed_on_the_fake_gateway(): void
    {
        $buyer = $this->makeUser();
        Sanctum::actingAs($buyer);

        $setup = $this->postJson('/api/v1/payment-methods/setup', [], $this->key('setup'))
            ->assertOk()
            ->json('data');
        $this->assertNull($setup['publishable_key']);
        $this->assertMatchesRegularExpression('/^seti_/', (string) $setup['id']);

        $this->postJson('/api/v1/payment-methods/setup/test-card', [
            'setup_intent_id' => $setup['id'],
        ], $this->key('save'))->assertOk()
            ->assertJsonPath('data.has_default', true)
            ->assertJsonPath('data.summary.last4', '4242')
            ->assertJsonPath('data.summary.brand', 'visa');

        $cardId = (string) $this->getJson('/api/v1/payment-methods')
            ->assertOk()
            ->assertJsonPath('data.cards.0.last4', '4242')
            ->assertJsonPath('data.cards.0.is_default', true)
            ->json('data.cards.0.id');

        $other = $this->makeUser();
        Sanctum::actingAs($other);
        $this->deleteJson('/api/v1/payment-methods/'.$cardId)->assertNotFound();

        Sanctum::actingAs($buyer);
        $this->deleteJson('/api/v1/payment-methods/'.$cardId)->assertNoContent();
        $this->getJson('/api/v1/payment-methods')->assertOk()->assertJsonPath('data.cards', []);
        $this->assertSame(0, DB::table('saved_payment_methods')->where('user_id', $buyer->id)->count());
    }

    public function test_the_test_card_shortcut_is_refused_when_stripe_is_configured(): void
    {
        $gateway = \Mockery::mock(PaymentGateway::class);
        $gateway->shouldReceive('supportsTestCards')->andReturn(false);
        $gateway->shouldReceive('createCustomer')->andReturn('cus_test_configured');
        $gateway->shouldReceive('createSetupIntent')->andReturn([
            'id' => 'seti_test_configured',
            'client_secret' => 'seti_test_configured_secret_test',
        ]);
        $this->app->instance(PaymentGateway::class, $gateway);
        Sanctum::actingAs($this->makeUser());
        $setup = $this->postJson('/api/v1/payment-methods/setup', [], $this->key('setup'))->assertOk()->json('data.id');
        $this->postJson('/api/v1/payment-methods/setup/test-card', [
            'setup_intent_id' => $setup,
        ], $this->key('save'))->assertStatus(409)->assertJsonPath('code', 'TEST_CARD_UNAVAILABLE');
    }
}

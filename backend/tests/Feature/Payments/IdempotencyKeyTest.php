<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsCommerce;
use Tests\Support\BuildsSocial;
use Tests\TestCase;

/** The Idempotency-Key contract on every route that moves money or reserves stock. */
final class IdempotencyKeyTest extends TestCase
{
    use BuildsCommerce;
    use BuildsSocial;
    use RefreshDatabase;

    /** @return iterable<string, array{0: string}> */
    public static function moneyRoutes(): iterable
    {
        yield 'checkout' => ['/api/v1/checkout'];
        yield 'one-tap' => ['/api/v1/checkout/one-tap'];
        yield 'gift' => ['/api/v1/gifts'];
        yield 'squad' => ['/api/v1/squads/authorize'];
        yield 'card setup' => ['/api/v1/payment-methods/setup'];
    }

    #[DataProvider('moneyRoutes')]
    public function test_money_routes_require_a_well_formed_key(string $route): void
    {
        Sanctum::actingAs($this->makeUser());
        foreach ([null, 'short', str_repeat('k', 81), 'has spaces in it', "new\nline-key", 'ünïcödé-key-1'] as $key) {
            $this->postJson($route, ['product_id' => (string) Str::uuid7()], $key === null ? [] : ['Idempotency-Key' => $key])
                ->assertStatus(422)
                ->assertJsonPath('code', 'IDEMPOTENCY_KEY_REQUIRED');
        }
        $this->assertSame(0, DB::table('orders')->count());
    }

    public function test_a_repeated_request_is_replayed_and_a_different_one_with_the_same_key_is_refused(): void
    {
        $buyer = $this->makeUser();
        $this->savedCard($buyer);
        $product = $this->catalogProduct(1_500);
        $other = $this->catalogProduct(1_500);
        Sanctum::actingAs($buyer);
        $headers = ['Idempotency-Key' => 'same-key-0001'];

        $first = $this->postJson('/api/v1/checkout', ['product_id' => $product, 'quantity' => 1], $headers)->assertCreated();
        $this->postJson('/api/v1/checkout', ['quantity' => 1, 'product_id' => $product], $headers)
            ->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('data.id', $first->json('data.id'));
        $this->postJson('/api/v1/checkout', ['product_id' => $other], $headers)->assertStatus(422)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
        $this->postJson('/api/v1/checkout/one-tap', ['product_id' => $product, 'quantity' => 1], $headers)->assertStatus(422)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');

        $this->assertSame(1, DB::table('orders')->where('buyer_id', $buyer->id)->count());
        $this->assertCount(1, $this->stripe()->authorizations);
    }

    public function test_keys_belong_to_one_user(): void
    {
        $product = $this->catalogProduct(1_000);
        foreach ([$this->makeUser(), $this->makeUser()] as $buyer) {
            $this->savedCard($buyer);
            Sanctum::actingAs($buyer);
            $this->postJson('/api/v1/checkout/one-tap', ['product_id' => $product], ['Idempotency-Key' => 'shared-key-123'])
                ->assertCreated()
                ->assertHeaderMissing('Idempotent-Replayed');
        }
        $this->assertSame(2, DB::table('orders')->count());
    }

    public function test_a_request_in_flight_blocks_its_twin_and_a_dead_claim_can_be_taken_over(): void
    {
        $buyer = $this->makeUser();
        $this->savedCard($buyer);
        $product = $this->catalogProduct(1_000);
        Sanctum::actingAs($buyer);
        $body = ['product_id' => $product];
        $this->claim($buyer->id, 'inflight-key-1', '/api/v1/checkout/one-tap', $body, lockedFor: 30);

        $this->postJson('/api/v1/checkout/one-tap', $body, ['Idempotency-Key' => 'inflight-key-1'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'REQUEST_IN_PROGRESS');
        $this->assertSame(0, DB::table('orders')->count());

        DB::table('idempotency_keys')->where('key', 'inflight-key-1')->update(['locked_until' => now()->subSecond()]);
        $this->postJson('/api/v1/checkout/one-tap', $body, ['Idempotency-Key' => 'inflight-key-1'])->assertCreated();
        $this->assertSame('completed', DB::table('idempotency_keys')->where('key', 'inflight-key-1')->value('status'));
    }

    public function test_server_errors_and_conflicts_release_the_key_for_a_retry(): void
    {
        $buyer = $this->makeUser();
        Sanctum::actingAs($buyer);
        $this->stripe()->failNext('createCustomer', 'unavailable');
        $this->postJson('/api/v1/payment-methods/setup', [], ['Idempotency-Key' => 'retry-me-001'])->assertStatus(503);
        $this->assertSame(0, DB::table('idempotency_keys')->where('key', 'retry-me-001')->count());
        config(['services.stripe.key' => 'pk_test_123']);
        $this->postJson('/api/v1/payment-methods/setup', [], ['Idempotency-Key' => 'retry-me-001'])
            ->assertOk()
            ->assertJsonPath('data.client_secret', fn (?string $secret): bool => is_string($secret) && $secret !== '')
            ->assertJsonPath('data.publishable_key', 'pk_test_123');
        $this->assertStringNotContainsString('_secret_', (string) DB::table('idempotency_keys')->where('key', 'retry-me-001')->value('response_body'));
        // The replay carries no secret: the app starts a new setup with a new key.
        $this->postJson('/api/v1/payment-methods/setup', [], ['Idempotency-Key' => 'retry-me-001'])
            ->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('data.client_secret', null)
            ->assertJsonPath('data.publishable_key', 'pk_test_123');

        $this->savedCard($buyer);
        $product = $this->catalogProduct(1_000, ['stock' => 0]);
        $this->postJson('/api/v1/checkout/one-tap', ['product_id' => $product], ['Idempotency-Key' => 'sold-out-01'])->assertStatus(409);
        DB::table('products')->where('id', $product)->update(['stock' => 1]);
        $this->postJson('/api/v1/checkout/one-tap', ['product_id' => $product], ['Idempotency-Key' => 'sold-out-01'])->assertCreated();
    }

    public function test_no_secret_is_ever_stored_for_a_replay(): void
    {
        Route::middleware(['auth:sanctum', 'idempotent'])->post('/api/v1/_secret-echo', fn () => new JsonResponse(['data' => [
            'client_secret' => 'pi_1_secret_a',
            'ephemeral_key_secret' => 'ek_test_b',
            'nested' => [
                'setup_intent_client_secret' => 'seti_1_secret_c',
                'next_action' => ['type' => 'use_stripe_sdk', 'client_secret' => 'pi_1_secret_d'],
            ],
            'secretary' => 'kept',
        ]], 201));
        Sanctum::actingAs($this->makeUser());

        $this->postJson('/api/v1/_secret-echo', [], ['Idempotency-Key' => 'secret-echo-1'])
            ->assertCreated()
            ->assertJsonPath('data.client_secret', 'pi_1_secret_a');
        $stored = (string) DB::table('idempotency_keys')->where('key', 'secret-echo-1')->value('response_body');
        foreach (['_secret_', 'ek_test_b', 'use_stripe_sdk'] as $needle) {
            $this->assertStringNotContainsString($needle, $stored);
        }

        $this->postJson('/api/v1/_secret-echo', [], ['Idempotency-Key' => 'secret-echo-1'])
            ->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('data.client_secret', null)
            ->assertJsonPath('data.ephemeral_key_secret', null)
            ->assertJsonPath('data.nested.setup_intent_client_secret', null)
            ->assertJsonPath('data.nested.next_action', null)
            ->assertJsonPath('data.secretary', 'kept');
    }

    public function test_oversized_and_non_json_answers_still_replay_as_completed(): void
    {
        Route::middleware(['auth:sanctum', 'idempotent'])->post('/api/v1/_big-answer', fn () => new JsonResponse(['data' => ['blob' => str_repeat('x', 70_000)]], 201));
        Route::middleware(['auth:sanctum', 'idempotent'])->post('/api/v1/_text-answer', fn () => response('created', 201));
        Sanctum::actingAs($this->makeUser());

        $this->postJson('/api/v1/_big-answer', [], ['Idempotency-Key' => 'big-answer-1'])->assertCreated();
        $this->postJson('/api/v1/_big-answer', [], ['Idempotency-Key' => 'big-answer-1'])
            ->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('code', 'IDEMPOTENT_REPLAY');

        $this->postJson('/api/v1/_text-answer', [], ['Idempotency-Key' => 'text-answer-1'])->assertCreated();
        $this->assertNull(DB::table('idempotency_keys')->where('key', 'text-answer-1')->value('response_body'));
        $this->postJson('/api/v1/_text-answer', [], ['Idempotency-Key' => 'text-answer-1'])
            ->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'true');
    }

    public function test_old_keys_are_pruned(): void
    {
        $user = $this->makeUser();
        $this->claim($user->id, 'ancient-key-1', '/api/v1/checkout', [], lockedFor: 0, createdAt: now()->subDays(2));
        $this->claim($user->id, 'recent-key-01', '/api/v1/checkout', [], lockedFor: 0);

        Artisan::call('idempotency:prune');

        $this->assertSame(['recent-key-01'], DB::table('idempotency_keys')->pluck('key')->all());
    }

    /** @param  array<string, mixed>  $body */
    private function claim(string $userId, string $key, string $path, array $body, int $lockedFor, ?DateTimeInterface $createdAt = null): void
    {
        $canonical = (string) json_encode(['input' => $this->sorted($body), 'files' => []], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        DB::table('idempotency_keys')->insert([
            'user_id' => $userId,
            'key' => $key,
            'route' => 'POST '.ltrim($path, '/'),
            'fingerprint' => hash('sha256', implode("\n", [$userId, 'POST', ltrim($path, '/'), $canonical])),
            'status' => 'in_progress',
            'locked_until' => now()->addSeconds($lockedFor),
            'created_at' => $createdAt ?? now(),
        ]);
    }

    private function sorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => $this->sorted($item), $value);
    }
}

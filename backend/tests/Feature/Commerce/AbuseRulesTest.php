<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Services\Commerce\LedgerService;
use App\Services\Commerce\RewardService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\Support\BuildsCommerce;
use Tests\Support\BuildsSocial;
use Tests\TestCase;

/** Business-logic abuse that needs no exploit: just an API client and patience. */
final class AbuseRulesTest extends TestCase
{
    use BuildsCommerce;
    use BuildsSocial;
    use RefreshDatabase;

    public function test_a_client_cannot_start_a_flash_drop_only_the_scheduler_can(): void
    {
        $this->catalogProduct(5_000);
        Sanctum::actingAs($this->makeUser());

        $this->postJson('/api/v1/drops/live')->assertOk()->assertJsonPath('data.drop', null);
        $this->getJson('/api/v1/drop-hour')->assertOk()->assertJsonPath('data.drop', null);
        $this->assertSame(0, DB::table('flash_drops')->count());
        $this->assertSame(0, DB::table('drop_hours')->count());

        Artisan::call('drops:rotate');
        Artisan::call('drops:rotate');
        $this->assertSame(1, DB::table('flash_drops')->count(), 'One live drop at a time.');
        $this->assertSame(1, DB::table('drop_hours')->count());
        $this->postJson('/api/v1/drops/live')->assertJsonPath('data.drop.discount_bps', (int) config('commerce.flash_drops.discount_bps'));
    }

    public function test_watch_rewards_use_the_server_duration_are_capped_daily_and_never_self_paid(): void
    {
        $viewer = $this->makeUser();
        $author = $this->makeUser();
        $rewards = app(RewardService::class);
        $posts = [];
        for ($i = 0; $i < RewardService::DAILY_LIMITS['watch'] + 3; $i++) {
            $posts[] = $this->video($author->id, 30_000);
        }

        $this->assertFalse($rewards->recordWatch($viewer, $posts[0], 1_000, 1_000), 'Claiming a 1-second video does not beat the stored 30 s duration.');
        $this->assertFalse($rewards->recordWatch($author, $posts[0], 30_000, 30_000), 'No reward for watching your own post.');
        $granted = 0;
        foreach ($posts as $post) {
            $granted += (int) $rewards->recordWatch($viewer, $post, 29_000, 30_000);
        }
        $this->assertSame(RewardService::DAILY_LIMITS['watch'], $granted);
        $this->assertFalse($rewards->recordWatch($viewer, $posts[0], 29_000, 30_000), 'Once per post.');
        $this->assertSame(RewardService::DAILY_LIMITS['watch'] * 15, (int) DB::table('user_wallets')->where('user_id', $viewer->id)->value('coins'));
        $this->assertSame(0, (int) DB::table('user_wallets')->where('user_id', $viewer->id)->value('cash_minor'));

        $this->expectException(LogicException::class);
        $rewards->grant($viewer, 'loot', null, 0, 0, 500);
    }

    public function test_the_database_refuses_paid_orders_without_a_capture_and_backward_payment_moves(): void
    {
        $buyer = $this->makeUser();
        $product = $this->catalogProduct();
        $insert = fn (array $override) => DB::transaction(fn () => DB::table('orders')->insert($override + [
            'id' => (string) Str::uuid7(),
            'buyer_id' => $buyer->id,
            'product_id' => $product,
            'quantity' => 1,
            'unit_price_minor' => 100,
            'total_minor' => 100,
            'currency' => 'USD',
            'idempotency_key' => 'db-'.Str::random(10),
            'created_at' => now(),
        ]));
        foreach ([
            ['status' => 'paid'],
            ['status' => 'paid', 'payment_status' => 'authorized'],
            ['status' => 'paid', 'payment_status' => 'captured', 'purpose' => 'legacy'],
            ['status' => 'processing', 'amount_refunded_minor' => 500],
        ] as $index => $bad) {
            try {
                $insert($bad);
                $this->fail("Bad order #{$index} was stored.");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        $order = $this->capturedOrder($buyer, $product, 100);
        foreach ([['payment_status' => 'authorized'], ['payment_status' => 'pending'], ['status' => 'processing']] as $index => $backwards) {
            try {
                DB::transaction(fn () => DB::table('orders')->where('id', $order)->update($backwards));
                $this->fail("Backward move #{$index} was allowed.");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('captured', DB::table('orders')->where('id', $order)->value('payment_status'));
        $defaulted = (string) Str::uuid7();
        DB::table('orders')->insert([
            'id' => $defaulted,
            'buyer_id' => $buyer->id,
            'product_id' => $product,
            'quantity' => 1,
            'unit_price_minor' => 100,
            'total_minor' => 100,
            'currency' => 'USD',
            'idempotency_key' => 'db-default-status',
            'created_at' => now(),
        ]);
        $this->assertSame('processing', DB::table('orders')->where('id', $defaulted)->value('status'), 'A forgotten status defaults to processing, never paid.');
    }

    public function test_every_money_job_is_scheduled(): void
    {
        $commands = collect(app(Schedule::class)->events())->map(fn ($event): string => (string) $event->command)->implode("\n");
        foreach (['payments:capture-due', 'payments:reconcile', 'ledger:release-commissions', 'price-protection:sweep', 'gifts:refund-expired', 'drops:rotate', 'idempotency:prune', 'ledger:verify'] as $job) {
            $this->assertStringContainsString($job, $commands);
        }
    }

    public function test_the_release_job_pays_kept_orders_and_the_ledger_verifies(): void
    {
        $creator = $this->makeUser();
        $buyer = $this->makeUser();
        $kept = $this->capturedOrder($buyer, $this->catalogProduct(), 5_000, ['creator_id' => $creator->id, 'created_at' => now()->subDays(20)]);
        $recent = $this->capturedOrder($buyer, $this->catalogProduct(), 5_000, ['creator_id' => $creator->id, 'created_at' => now()->subDays(2)]);
        foreach ([$kept, $recent] as $order) {
            app(LedgerService::class)->pendCommission($creator->id, $order, 400, 'USD');
        }

        $this->assertSame(0, Artisan::call('ledger:release-commissions'));
        Artisan::call('ledger:release-commissions');

        $this->assertSame(400, (int) DB::table('user_wallets')->where('user_id', $creator->id)->value('cash_minor'));
        $this->assertSame(1, DB::table('reward_events')->where('kind', 'purchase')->where('user_id', $buyer->id)->count());
        $this->assertSame(0, Artisan::call('ledger:verify'));
    }

    public function test_capture_and_reconcile_commands_run_end_to_end(): void
    {
        $buyer = $this->makeUser();
        $this->savedCard($buyer);
        Sanctum::actingAs($buyer);
        $order = (string) $this->postJson('/api/v1/checkout/one-tap', ['product_id' => $this->catalogProduct(900)], $this->key('cmd'))->json('data.order.id');
        $this->expireUndoWindow($order);

        $this->assertSame(0, Artisan::call('payments:reconcile'));
        $this->assertSame(0, Artisan::call('payments:capture-due'));
        $this->assertSame('captured', DB::table('orders')->where('id', $order)->value('payment_status'));
    }

    private function video(string $authorId, int $durationMs): string
    {
        $id = (string) Str::uuid7();
        DB::table('feed_posts')->insert([
            'id' => $id,
            'user_id' => $authorId,
            'kind' => 'media',
            'audience' => 'public',
            'media_type' => 'video',
            'media_path' => 'posts/'.$id.'.mp4',
            'thumbnail_path' => 'posts/'.$id.'.jpg',
            'media_duration_ms' => $durationMs,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}

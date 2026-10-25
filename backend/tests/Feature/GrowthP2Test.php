<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Commerce\AutoTagService;
use App\Services\Commerce\PriceGuard;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\BuildsCommerce;
use Tests\Support\BuildsSocial;
use Tests\TestCase;

final class GrowthP2Test extends TestCase
{
    use BuildsCommerce;
    use BuildsSocial;
    use RefreshDatabase;

    public function test_a_video_frame_attaches_the_matching_product_only(): void
    {
        $jpeg = $this->reelImage(7);
        $match = $this->indexedProduct($jpeg, 10000);
        $miss = $this->indexedProduct($this->reelImage(8), 10000);
        $post = $this->reel($this->makeUser()->id);

        $suggestions = app(AutoTagService::class)->match($post, $jpeg);

        $this->assertSame($match, $suggestions[0]['product_id']);
        $this->assertSame('attached', $suggestions[0]['status']);
        $this->assertSame($match, DB::table('reel_product_tags')->where('post_id', $post)->value('product_id'));
        $this->assertSame('auto', DB::table('reel_product_tags')->where('post_id', $post)->value('source'));
        $this->assertFalse(collect($suggestions)->contains('product_id', $miss));
    }

    #[Group('purchases')]
    public function test_synthetic_track_keeps_the_ai_label(): void
    {
        $author = $this->makeUser();
        $post = $this->reel($author->id);
        Sanctum::actingAs($author);

        $this->postJson('/api/v1/feed/'.$post.'/synthetic', [
            'language' => 'de',
            'kind' => 'subtitle',
        ])->assertCreated()
            ->assertJsonPath('data.label', 'AI-generated')
            ->assertJsonPath('data.disclosed', true)
            ->assertJsonPath('data.tracks.0.audio_url', null);

        $this->assertStringContainsString('AI-generated', (string) DB::table('synthetic_tracks')->value('cues'));

        try {
            DB::table('synthetic_tracks')->insert([
                'id' => (string) Str::uuid7(),
                'post_id' => $post,
                'language' => 'fr',
                'kind' => 'dub',
                'label' => 'AI-generated',
                'disclosed' => false,
                'cues' => 'AI-generated',
                'created_at' => now(),
            ]);
            $this->fail('An unlabeled synthetic track was stored.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('synthetic_tracks', $exception->getMessage());
        }
    }

    public function test_a_price_drop_inside_14_days_refunds_the_difference_once(): void
    {
        $buyer = $this->makeUser();
        $product = $this->product(10000, null);
        $order = $this->capturedOrder($buyer, $product, 20000, ['quantity' => 2, 'unit_price_minor' => 10000, 'created_at' => now()->subDays(3)]);
        $guard = app(PriceGuard::class);
        $guard->cover($order);
        DB::table('products')->where('id', $product)->update(['price_minor' => 8000, 'squad_price_minor' => 5600]);

        // The new price has not held for a day yet: nothing is paid.
        $this->assertSame(0, $guard->sweep($product));
        $this->holdCurrentPrice($product, hours: 25);
        $this->assertSame(1, $guard->sweep($product));
        $this->assertSame(4000, (int) DB::table('user_wallets')->where('user_id', $buyer->id)->value('cash_minor'));
        $this->assertSame(0, $guard->sweep($product));

        DB::table('price_protections')->where('order_id', $order)->update(['protected_until' => now()->subDay()]);
        DB::table('products')->where('id', $product)->update(['price_minor' => 5000, 'squad_price_minor' => 3500]);
        $this->holdCurrentPrice($product, hours: 25);
        $this->assertSame(0, $guard->sweep($product));
        $this->assertSame(4000, (int) DB::table('price_protections')->where('order_id', $order)->value('refunded_minor'));
    }

    #[Group('purchases')]
    public function test_reviews_and_answers_require_a_verified_purchase(): void
    {
        $buyer = $this->makeUser();
        $stranger = $this->makeUser();
        $product = $this->product(2500, null);
        $order = $this->capturedOrder($buyer, $product, 2500);

        Sanctum::actingAs($stranger);
        $this->postJson('/api/v1/products/'.$product.'/reviews', [
            'order_id' => $order,
            'body' => 'Looks great',
        ])->assertStatus(403);

        Sanctum::actingAs($buyer);
        $this->postJson('/api/v1/products/'.$product.'/reviews', [
            'order_id' => $order,
            'body' => 'Kept it',
        ])->assertCreated()->assertJsonPath('data.reviews.0.verified_purchase', true);

        $question = $this->postJson('/api/v1/products/'.$product.'/questions', ['body' => 'Does it run small?'])
            ->assertCreated()
            ->json('data.questions.0.id');

        Sanctum::actingAs($stranger);
        $this->postJson('/api/v1/questions/'.$question.'/answer', ['body' => 'No'])->assertStatus(403);

        Sanctum::actingAs($buyer);
        $this->postJson('/api/v1/questions/'.$question.'/answer', ['body' => 'True to size'])
            ->assertCreated()
            ->assertJsonPath('data.questions.0.answer.verified_purchase', true);

        try {
            DB::table('item_reviews')->insert([
                'id' => (string) Str::uuid7(),
                'product_id' => $product,
                'order_id' => $order,
                'author_id' => $stranger->id,
                'body' => 'Not my order',
                'created_at' => now(),
            ]);
            $this->fail('A review without a matching purchase was stored.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('verified purchase', $exception->getMessage());
        }
    }

    private function product(int $price, ?string $embedding): string
    {
        $id = (string) Str::uuid7();
        DB::table('products')->insert([
            'id' => $id,
            'title' => 'Piece '.substr($id, 0, 8),
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
        if ($embedding !== null) {
            DB::table('products')->where('id', $id)->update(['embedding' => $embedding]);
        }

        return $id;
    }

    /** Pretends the current price went live $hours ago: the whole history moves back (the trigger stamps real time). */
    private function holdCurrentPrice(string $productId, int $hours): void
    {
        DB::update(
            'UPDATE product_price_history
                SET valid_from = valid_from - make_interval(hours => ?), valid_to = valid_to - make_interval(hours => ?)
              WHERE product_id = ?',
            [$hours, $hours, $productId],
        );
    }

    private function reel(string $authorId): string
    {
        $id = (string) Str::uuid7();
        DB::table('feed_posts')->insert([
            'id' => $id,
            'user_id' => $authorId,
            'kind' => 'media',
            'audience' => 'public',
            'media_type' => 'image',
            'media_path' => 'posts/'.$id.'.jpg',
            'caption' => 'Night market',
            'tags' => json_encode(['studio']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}

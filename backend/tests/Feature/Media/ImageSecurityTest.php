<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use App\Exceptions\ApiException;
use App\Services\Media\ImageFetchFailed;
use App\Services\Media\ImageGuard;
use App\Services\Media\SafeImageFetcher;
use App\Services\Media\VisualEmbedding;
use App\Services\Media\VisualSignature;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsCommerce;
use Tests\Support\BuildsSocial;
use Tests\Support\HostileImages;
use Tests\TestCase;

/**
 * Hostile uploads against the image trust boundary (ImageGuard), the visual_v2 signature, the SSRF-safe
 * fetcher, and the HTTP endpoints that accept images.
 */
final class ImageSecurityTest extends TestCase
{
    use BuildsCommerce;
    use BuildsSocial;
    use RefreshDatabase;

    public function test_real_images_of_every_allowed_type_are_accepted(): void
    {
        $jpeg = HostileImages::variant($this->reelImage(3), 400, 85);
        foreach ([$jpeg, HostileImages::png(), HostileImages::webp($jpeg)] as $bytes) {
            $image = ImageGuard::decode($bytes, strtoupper(hash('sha256', $bytes)));
            $this->assertGreaterThanOrEqual(32, $image->width);
            $this->assertSame(hash('sha256', $bytes), $image->sha256);
        }
        $this->assertSame(400, ImageGuard::decodeBase64('data:image/jpeg;base64,'.base64_encode($jpeg))->width);
    }

    /** @return iterable<string, array{0: Closure(): string, 1: string}> */
    public static function hostileFiles(): iterable
    {
        yield 'empty' => [fn (): string => '', 'Send an image'];
        yield 'decompression bomb' => [fn (): string => HostileImages::pngBomb(), 'too many pixels'];
        yield 'one huge edge' => [fn (): string => HostileImages::pngBomb(20000, 40), 'too many pixels'];
        yield 'svg with script' => [fn (): string => HostileImages::svg(), 'JPEG, PNG or WebP'];
        yield 'gif' => [fn (): string => HostileImages::gif(), 'JPEG, PNG or WebP'];
        yield 'html' => [fn (): string => '<html><body>hello</body></html>', 'JPEG, PNG or WebP'];
        yield 'png wearing a jpeg magic' => [fn (): string => HostileImages::mislabelled(HostileImages::png()), 'could not be read'];
        yield 'tiny' => [fn (): string => HostileImages::png(16, 16), 'too small'];
        yield 'truncated header' => [fn (): string => "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00", 'could not be read'];
        yield 'over the size limit' => [fn (): string => "\xFF\xD8\xFF".str_repeat('A', 8 * 1024 * 1024), 'larger than'];
        yield 'five bytes of garbage' => [fn (): string => 'GIF89', 'JPEG, PNG or WebP'];
        yield 'valid header, corrupt pixels' => [fn (): string => HostileImages::corruptPng(), 'could not be decoded'];
    }

    #[DataProvider('hostileFiles')]
    public function test_hostile_files_are_refused_before_decoding(Closure $bytes, string $reason): void
    {
        $before = memory_get_usage(true);
        try {
            ImageGuard::decode($bytes());
            $this->fail('A hostile file was accepted.');
        } catch (ApiException $refused) {
            $this->assertSame('IMAGE_REJECTED', $refused->errorCode);
            $this->assertStringContainsString($reason, $refused->getMessage());
        }
        $this->assertLessThan(64 * 1024 * 1024, memory_get_usage(true) - $before, 'Nothing large was allocated.');
    }

    public function test_integrity_and_base64_tricks_are_refused(): void
    {
        $jpeg = HostileImages::variant($this->reelImage(5), 200, 85);
        $this->assertRejected(fn () => ImageGuard::decode($jpeg, hash('sha256', $jpeg.'x')), 'IMAGE_HASH_MISMATCH');
        $this->assertRejected(fn () => ImageGuard::decode($jpeg, 'not-a-hash'), 'IMAGE_HASH_MISMATCH');
        $this->assertRejected(fn () => ImageGuard::decodeBase64('data:image/svg+xml;base64,'.base64_encode(HostileImages::svg())), 'IMAGE_REJECTED');
        $this->assertRejected(fn () => ImageGuard::decodeBase64('%%%not base64%%%'), 'IMAGE_REJECTED');
        $this->assertRejected(fn () => ImageGuard::decodeBase64(str_repeat('QUFB', 3_000_000)), 'IMAGE_REJECTED');
        // A polyglot decodes as the JPEG it starts with; it is analysed, never stored or served back.
        $this->assertSame(200, ImageGuard::decode(HostileImages::polyglot($jpeg))->width);
    }

    public function test_visual_signatures_survive_re_encoding_and_separate_different_photos(): void
    {
        $original = $this->reelImage(11);
        $base = VisualEmbedding::fromBytes($original);
        $this->assertCount(64, $base->luma);
        $this->assertCount(64, $base->structure);
        $this->assertSame(64, strlen($base->dhash));
        $this->assertEqualsWithDelta(0.0, array_sum($base->luma), 1e-6, 'Luma is zero-mean (cosine = correlation).');
        $this->assertEqualsWithDelta(1.0, sqrt(array_sum(array_map(fn (float $v): float => $v * $v, $base->luma))), 1e-6);

        foreach ([HostileImages::variant($original, 300, 55), HostileImages::variant($original, 1200, 95), HostileImages::variant($original, 640, 80, 35)] as $copy) {
            $other = VisualEmbedding::fromBytes($copy);
            $this->assertGreaterThan(0.98, VisualSignature::cosine($base->luma, $other->luma));
            $this->assertGreaterThan(0.97, VisualSignature::cosine($base->structure, $other->structure));
            $this->assertLessThanOrEqual(4, VisualSignature::hamming($base->dhash, $other->dhash));
        }
        $falseMatches = 0;
        for ($seed = 100; $seed < 160; $seed++) {
            $other = VisualEmbedding::fromBytes($this->reelImage($seed));
            if (VisualSignature::cosine($base->luma, $other->luma) >= 0.85 && VisualSignature::cosine($base->structure, $other->structure) >= 0.80) {
                $falseMatches++;
            }
        }
        $this->assertSame(0, $falseMatches);
        $this->assertRejected(fn () => VisualEmbedding::fromBytes(HostileImages::flatJpeg()), 'IMAGE_REJECTED');
        $this->assertRejected(fn () => VisualEmbedding::fromBytes(HostileImages::png(64, 64, flat: true)), 'IMAGE_REJECTED');
    }

    public function test_the_fetcher_refuses_anything_but_public_https_hosts(): void
    {
        $dns = [
            'cdn.example.com' => ['93.184.216.34'],
            'rebind.example.com' => ['93.184.216.34', '10.0.0.5'],
            'metadata.example.com' => ['169.254.169.254'],
            'v6.example.com' => ['::1'],
            'cgnat.example.com' => ['100.64.1.1'],
        ];
        $fetcher = new SafeImageFetcher(resolver: fn (string $host): array => $dns[$host] ?? []);

        $this->assertSame(['cdn.example.com', '93.184.216.34', '/a/b.jpg?w=1'], $fetcher->target('https://cdn.example.com/a/b.jpg?w=1'));
        foreach ([
            'http://cdn.example.com/a.jpg' => 'https',
            'ftp://cdn.example.com/a.jpg' => 'https',
            'https://user:pass@cdn.example.com/a.jpg' => 'credentials',
            'https://cdn.example.com:8443/a.jpg' => 'standard https port',
            'https://127.0.0.1/a.jpg' => 'not an IP',
            'https://[::1]/a.jpg' => 'not an IP',
            'https://169.254.169.254/latest/meta-data/' => 'not an IP',
            'https://metadata.example.com/a.jpg' => 'private address',
            'https://rebind.example.com/a.jpg' => 'private address',
            'https://v6.example.com/a.jpg' => 'private address',
            'https://cgnat.example.com/a.jpg' => 'private address',
            'https://nowhere.example.com/a.jpg' => 'does not resolve',
            "https://cdn.example.com/a.jpg\r\nHost: evil" => 'not valid',
            'https://'.str_repeat('a', 600).'.com/x.jpg' => 'not valid',
            'https://under_score.example.com/a.jpg' => 'not valid',
        ] as $url => $reason) {
            try {
                $fetcher->target($url);
                $this->fail("Fetched {$url}");
            } catch (ImageFetchFailed $refused) {
                $this->assertStringContainsString($reason, $refused->getMessage(), $url);
            }
        }
        $this->assertFalse(SafeImageFetcher::isPublic('::ffff:127.0.0.1'));
        $this->assertTrue(SafeImageFetcher::isPublic('2606:4700:4700::1111'));
    }

    public function test_upload_endpoints_refuse_hostile_images_with_a_clean_error(): void
    {
        $viewer = $this->makeUser();
        Sanctum::actingAs($viewer);
        $bomb = HostileImages::pngBomb();
        $this->post('/api/v1/visual-search', ['image_base64' => base64_encode($bomb)], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('code', 'IMAGE_REJECTED');
        $this->post('/api/v1/visual-search', ['image' => UploadedFile::fake()->createWithContent('cat.svg', HostileImages::svg())], ['Accept' => 'application/json'])
            ->assertStatus(422);
        $this->post('/api/v1/visual-search', ['image' => UploadedFile::fake()->createWithContent('bomb.png', $bomb)], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('code', 'IMAGE_REJECTED');
        $jpeg = $this->reelImage(6);
        $this->post('/api/v1/visual-search', ['image_base64' => base64_encode($jpeg), 'image_sha256' => hash('sha256', 'other')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('code', 'IMAGE_HASH_MISMATCH');
        $this->post('/api/v1/visual-search', ['image_base64' => base64_encode(HostileImages::flatJpeg())], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('code', 'IMAGE_REJECTED');
        $this->post('/api/v1/visual-search', [], ['Accept' => 'application/json'])->assertStatus(422);
        $this->post('/api/v1/visual-search', ['image_base64' => str_repeat('A', 11_184_817)], ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertSame(0, DB::table('visual_scans')->count(), 'A refused upload leaves no scan record.');

        $author = $this->makeUser();
        $post = (string) Str::uuid7();
        DB::table('feed_posts')->insert(['id' => $post, 'user_id' => $author->id, 'kind' => 'media', 'audience' => 'public', 'media_type' => 'image', 'media_path' => 'posts/'.$post.'.jpg', 'created_at' => now(), 'updated_at' => now()]);
        Sanctum::actingAs($author);
        $this->post('/api/v1/feed/'.$post.'/auto-tag', ['frame' => UploadedFile::fake()->createWithContent('frame.png', $bomb)], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('code', 'IMAGE_REJECTED');
        $this->post('/api/v1/feed/'.$post.'/auto-tag', ['frame' => UploadedFile::fake()->createWithContent('frame.svg', HostileImages::svg())], ['Accept' => 'application/json'])
            ->assertStatus(422);
        Sanctum::actingAs($viewer);
        $this->post('/api/v1/feed/'.$post.'/auto-tag', ['frame' => UploadedFile::fake()->createWithContent('frame.jpg', $jpeg)], ['Accept' => 'application/json'])
            ->assertStatus(404);
    }

    public function test_the_canonical_fallback_never_reveals_a_hidden_post(): void
    {
        $viewer = $this->makeUser();
        $author = $this->makeUser();
        $product = $this->catalogProduct();
        $post = (string) Str::uuid7();
        DB::table('feed_posts')->insert(['id' => $post, 'user_id' => $author->id, 'kind' => 'media', 'audience' => 'friends', 'media_type' => 'image', 'media_path' => 'posts/'.$post.'.jpg', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('reel_product_tags')->insert(['post_id' => $post, 'product_id' => $product, 'created_at' => now()]);
        Sanctum::actingAs($viewer);

        $this->post('/api/v1/visual-search', ['image_base64' => base64_encode($this->reelImage(21)), 'post_id' => $post], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.results', [])
            ->assertJsonPath('data.fallback', null);
    }

    private function assertRejected(Closure $call, string $code): void
    {
        try {
            $call();
            $this->fail('Expected '.$code);
        } catch (ApiException $refused) {
            $this->assertSame($code, $refused->errorCode);
        }
    }
}

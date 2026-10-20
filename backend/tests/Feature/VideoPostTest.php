<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FeedPost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\BuildsSocial;
use Tests\TestCase;

/**
 * Video posts through the real ffmpeg pipeline: a phone-style .mov with a GPS location tag and a
 * 90-degree display rotation must come out as a streamable MP4 without the location, still portrait.
 */
final class VideoPostTest extends TestCase
{
    use BuildsSocial;
    use RefreshDatabase;

    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();
        if (! Process::run(['ffmpeg', '-version'])->successful() || ! Process::run(['ffprobe', '-version'])->successful()) {
            $this->markTestSkipped('ffmpeg/ffprobe are not installed on this machine.');
        }
        Storage::fake('public');
        config(['feed.media_disk' => 'public', 'feed.video.processor' => 'ffmpeg']);
        $this->workDir = sys_get_temp_dir().'/video-test-'.uniqid();
        mkdir($this->workDir);
    }

    protected function tearDown(): void
    {
        if (isset($this->workDir) && is_dir($this->workDir)) {
            array_map('unlink', glob($this->workDir.'/*') ?: []);
            rmdir($this->workDir);
        }
        parent::tearDown();
    }

    /** iPhone-like recording: QuickTime, H.264 + AAC, location metadata, rotated for portrait display. */
    private function phoneVideo(float $seconds = 2.0): string
    {
        $raw = $this->workDir.'/raw.mov';
        $out = $this->workDir.'/phone.mov';
        Process::run(['ffmpeg', '-nostdin', '-v', 'error', '-y', '-f', 'lavfi', '-i', "testsrc=duration={$seconds}:size=320x240:rate=25",
            '-f', 'lavfi', '-i', "sine=frequency=440:duration={$seconds}", '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-c:a', 'aac', $raw])->throw();
        Process::run(['ffmpeg', '-nostdin', '-v', 'error', '-y', '-display_rotation', '90', '-i', $raw, '-c', 'copy',
            '-metadata', 'location=+40.7128-074.0060/', $out])->throw();

        return $out;
    }

    public function test_video_is_stripped_of_location_streamable_and_keeps_portrait_orientation(): void
    {
        Sanctum::actingAs($this->makeUser());
        $source = $this->phoneVideo();
        $this->assertStringContainsString('location', Process::run(['ffprobe', '-v', 'error', '-show_format', '-of', 'json', $source])->output());

        $response = $this->post('/api/v1/feed', [
            'media' => new UploadedFile($source, 'IMG_0420.MOV', 'video/quicktime', null, true),
            'caption' => 'Unboxing',
            'audience' => 'public',
            'duration_ms' => 590_000,   // a wrong client value: the server measures
        ], ['Accept' => 'application/json'])->assertCreated();

        $response->assertJsonPath('data.media.type', 'video')
            ->assertJsonPath('data.media.width', 240)
            ->assertJsonPath('data.media.height', 320);
        $this->assertEqualsWithDelta(2000, $response->json('data.media.duration_ms'), 100);

        $post = FeedPost::query()->firstOrFail();
        $stored = Storage::disk('public')->path($post->media_path);
        $probe = json_decode(Process::run(['ffprobe', '-v', 'error', '-show_streams', '-show_format', '-of', 'json', $stored])->output(), true);

        $this->assertArrayNotHasKey('location', $probe['format']['tags'] ?? []);
        $this->assertArrayNotHasKey('location-eng', $probe['format']['tags'] ?? []);
        $bytes = (string) file_get_contents($stored);
        $this->assertLessThan(strpos($bytes, 'mdat'), strpos($bytes, 'moov'), 'moov atom first (+faststart)');
        $video = collect($probe['streams'])->firstWhere('codec_type', 'video');
        $this->assertSame(90, abs((int) ($video['side_data_list'][0]['rotation'] ?? 0)), 'display rotation preserved');

        Storage::disk('public')->assertExists($post->thumbnail_path);   // poster extracted server-side
    }

    public function test_overlong_and_fake_videos_are_rejected(): void
    {
        Sanctum::actingAs($this->makeUser());
        config(['feed.video.max_duration_ms' => 1_000]);

        $this->post('/api/v1/feed', [
            'media' => new UploadedFile($this->phoneVideo(2.0), 'long.mov', 'video/quicktime', null, true),
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('code', 'MEDIA_REJECTED');

        file_put_contents($this->workDir.'/fake.mp4', random_bytes(4096));
        $this->post('/api/v1/feed', [
            'media' => new UploadedFile($this->workDir.'/fake.mp4', 'fake.mp4', 'video/mp4', null, true),
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertSame(0, FeedPost::query()->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }
}

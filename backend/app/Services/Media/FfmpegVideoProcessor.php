<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Exceptions\ApiException;
use App\Services\Media\Contracts\VideoProcessor;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Str;

/**
 * Probes and re-muxes uploads with ffmpeg (no re-encode, so it takes about as long as copying the file):
 *  - rejects files without an H.264/HEVC video stream (what iOS and Android players decode in hardware);
 *  - measures the real duration (the client's number is never trusted);
 *  - strips ALL metadata: phone videos carry the recording location (QuickTime ©xyz / mdta atoms);
 *  - +faststart: moves the moov atom to the front so playback starts on the first bytes from the CDN;
 *  - extracts a poster frame when the app did not send one.
 * Rotation is display-matrix side data, which stream copy preserves, so portrait videos stay portrait.
 */
final readonly class FfmpegVideoProcessor implements VideoProcessor
{
    private const ALLOWED_CODECS = ['h264', 'hevc'];

    public function __construct(
        private ProcessFactory $processes,
        private string $ffmpeg,
        private string $ffprobe,
        private int $timeoutSeconds,
    ) {}

    public function process(string $path, ?int $clientDurationMs): ProcessedVideo
    {
        $probe = $this->processes->timeout(30)->run([
            $this->ffprobe, '-v', 'error', '-show_streams', '-show_format', '-of', 'json', $path,
        ]);
        $info = json_decode($probe->output(), true);
        if (! $probe->successful() || ! is_array($info)) {
            throw ApiException::mediaRejected('This video could not be read.');
        }

        $video = null;
        foreach ($info['streams'] ?? [] as $stream) {
            if (($stream['codec_type'] ?? null) === 'video' && ($stream['disposition']['attached_pic'] ?? 0) !== 1) {
                $video = $stream;
                break;
            }
        }
        if ($video === null || ! in_array($video['codec_name'] ?? '', self::ALLOWED_CODECS, true)) {
            throw ApiException::mediaRejected('Upload an H.264 or HEVC video (the default on iPhone and Android cameras).');
        }

        $seconds = (float) ($info['format']['duration'] ?? $video['duration'] ?? 0);
        if ($seconds <= 0) {
            throw ApiException::mediaRejected('This video has no playable duration.');
        }

        [$width, $height] = [(int) ($video['width'] ?? 0), (int) ($video['height'] ?? 0)];
        if (abs($this->rotation($video)) % 180 === 90) {
            [$width, $height] = [$height, $width];
        }

        $base = sys_get_temp_dir().'/feed-'.Str::uuid7();
        $output = $base.'.mp4';
        $remux = $this->processes->timeout($this->timeoutSeconds)->run([
            $this->ffmpeg, '-nostdin', '-v', 'error', '-y', '-i', $path,
            '-map', '0:v:0', '-map', '0:a:0?', '-c', 'copy',
            '-map_metadata', '-1', '-map_metadata:s:v', '-1', '-map_metadata:s:a', '-1', '-map_chapters', '-1',
            '-movflags', '+faststart', '-f', 'mp4', $output,
        ]);
        if (! $remux->successful() || ! is_file($output) || filesize($output) === 0) {
            @unlink($output);
            throw ApiException::mediaRejected('This video could not be processed.');
        }

        $poster = $base.'.jpg';
        $frame = $this->processes->timeout(30)->run([
            $this->ffmpeg, '-nostdin', '-v', 'error', '-y', '-ss', $seconds > 1 ? '0.5' : '0', '-i', $output,
            '-frames:v', '1', '-vf', "scale='min(1080,iw)':-2", '-q:v', '3', $poster,
        ]);

        return new ProcessedVideo(
            $output,
            (int) round($seconds * 1000),
            $width > 0 ? $width : null,
            $height > 0 ? $height : null,
            $frame->successful() && is_file($poster) && filesize($poster) > 0 ? $poster : null,
        );
    }

    /** Degrees from the display matrix side data (ffmpeg >= 5) or the legacy "rotate" tag. */
    private function rotation(array $stream): int
    {
        foreach ($stream['side_data_list'] ?? [] as $sideData) {
            if (isset($sideData['rotation'])) {
                return (int) $sideData['rotation'];
            }
        }

        return (int) ($stream['tags']['rotate'] ?? 0);
    }
}

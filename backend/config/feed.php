<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Reels-style feed
|--------------------------------------------------------------------------
| Media is uploaded to object storage (S3/R2 in production) and served through a
| CDN; the database stores storage keys only. Videos are re-muxed with ffmpeg on
| upload: metadata (incl. GPS location atoms) is stripped and the moov atom moved
| to the front (+faststart) so playback starts before the download finishes.
*/

return [

    'media_disk' => env('FEED_MEDIA_DISK', 'public'),
    // Public base URL of the CDN in front of the media disk (e.g. https://cdn.example.com).
    // Empty = use the disk's own url().
    'cdn_url' => env('FEED_CDN_URL', ''),

    // Public reel links: https://<domain>/p/{post}
    'share_base_url' => env('SHARE_BASE_URL', env('APP_URL', 'http://localhost')),

    'page_size' => 10,
    'max_page_size' => 30,

    // Shared "For You" ranking: the ordered post ids of the first pages are cached and re-filtered per
    // viewer (visibility, blocks), so a burst of app opens costs one ranking query.
    'for_you_cache_seconds' => 30,
    'for_you_cached_pages' => 3,

    'video' => [
        'max_kb' => 102_400,           // 100 MB
        'max_duration_ms' => 180_000,  // 3 minutes
        'mimetypes' => ['video/mp4', 'video/quicktime'],
        // 'ffmpeg' strips metadata + faststart (needs ffmpeg/ffprobe on the host or the media worker);
        // 'passthrough' stores the file as uploaded and trusts the client's duration (local dev only).
        'processor' => env('FEED_VIDEO_PROCESSOR', 'ffmpeg'),
        'ffmpeg_binary' => env('FFMPEG_BINARY', 'ffmpeg'),
        'ffprobe_binary' => env('FFPROBE_BINARY', 'ffprobe'),
        'process_timeout_seconds' => 120,
    ],

    'image' => [
        'max_kb' => 15_360,
        'max_edge' => 2_160,           // re-encoded to JPEG, EXIF stripped, longest edge capped
        'mimetypes' => ['image/jpeg', 'image/png', 'image/webp'],
    ],

    'caption_max' => 2_000,
    'max_tags' => 10,

    'hashtags' => [
        'search_limit' => 20,
        'trending_limit' => 20,
        'trending_days' => 7,
    ],

    'comments' => [
        'page_size' => 20,
        'max_length' => 1_000,
    ],

    // Rate limits per user
    'limits' => [
        'posts_per_hour' => 20,
        'likes_per_minute' => 120,
        'comments_per_minute' => 10,
        'shares_per_minute' => 30,
    ],
];

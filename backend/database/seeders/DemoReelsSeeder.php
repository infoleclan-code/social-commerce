<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** A handful of public image reels so Reels and Explore are not empty in the demo. */
class DemoReelsSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            return;
        }
        $author = DB::table('users')->whereRaw('username = ?', ['demo_reels'])->first();
        if ($author === null || DB::table('feed_posts')->where('user_id', $author->id)->where('caption', 'Night market sneakers')->exists()) {
            return;
        }

        $posts = [
            ['Night market sneakers', ['sneakers', 'streetstyle'], 'https://images.unsplash.com/photo-1542291026-7eec264c27ff?auto=format&fit=crop&w=800&q=70'],
            ['Saturday drop', ['drops', 'sneakers'], 'https://images.unsplash.com/photo-1600185365483-26d7a4cc7519?auto=format&fit=crop&w=800&q=70'],
            ['Vintage runner', ['vintage', 'streetstyle'], 'https://images.unsplash.com/photo-1549298916-b41d501d3772?auto=format&fit=crop&w=800&q=70'],
            ['Studio still', ['drops', 'vintage'], 'https://images.unsplash.com/photo-1514989940723-e8e51635b782?auto=format&fit=crop&w=800&q=70'],
        ];
        $firstId = null;
        foreach ($posts as $post) {
            $id = (string) Str::uuid7();
            $firstId ??= $id;
            DB::table('feed_posts')->insert([
                'id' => $id,
                'user_id' => $author->id,
                'kind' => 'media',
                'caption' => $post[0],
                'audience' => 'public',
                'media_type' => 'image',
                'media_path' => $post[2],
                'media_width' => 800,
                'media_height' => 1000,
                'tags' => json_encode($post[1], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::table('feed_post_comments')->insert([
            'id' => (string) Str::uuid7(),
            'post_id' => $firstId,
            'user_id' => $author->id,
            'body' => 'The sole on these is perfect.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->command?->info('Added '.count($posts).' demo reels.');
    }
}

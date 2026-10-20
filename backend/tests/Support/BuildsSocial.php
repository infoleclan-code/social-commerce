<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\ConnectionStatus;
use App\Models\SocialConnection;
use App\Models\User;
use Illuminate\Support\Str;

/** Fixture builders for the social feed (real PostgreSQL rows). */
trait BuildsSocial
{
    protected function makeUser(array $attributes = [], bool $socialOptIn = true): User
    {
        $handle = 'u'.substr(str_replace('-', '', (string) Str::uuid7()), -12);

        return User::query()->create($attributes + [
            'email' => $handle.'@example.com',
            'username' => $handle,
            'display_name' => ucfirst($handle),
            'social_feed_enabled' => $socialOptIn,
        ])->refresh();
    }

    protected function befriend(User $a, User $b): void
    {
        $connection = new SocialConnection(['addressee_id' => $b->id]);
        $connection->forceFill(['requester_id' => $a->id])->save();
        $connection->transitionTo(ConnectionStatus::Accepted, ['responded_at' => now()]);
    }

    protected function block(User $blocker, User $blocked): void
    {
        $connection = new SocialConnection(['addressee_id' => $blocked->id]);
        $connection->forceFill([
            'requester_id' => $blocker->id,
            'status' => 'blocked',
            'blocked_by_user_id' => $blocker->id,
        ])->save();
    }

    /** A synthetic JPEG used as reel upload bytes. */
    protected function reelImage(int $seed, int $width = 800, int $height = 800): string
    {
        mt_srand($seed);
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 245, 245, 245));
        $color = imagecolorallocate($image, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255));
        $accent = imagecolorallocate($image, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255));
        for ($i = 0; $i < 6; $i++) {
            imagefilledellipse($image, mt_rand(200, $width - 200), mt_rand(200, $height - 200), mt_rand(80, 300), mt_rand(80, 300), $i % 2 ? $color : $accent);
        }
        imagefilledrectangle($image, mt_rand(150, 300), mt_rand(150, 300), mt_rand(400, 650), mt_rand(400, 650), $color);
        ob_start();
        imagejpeg($image, null, 90);

        return (string) ob_get_clean();
    }
}

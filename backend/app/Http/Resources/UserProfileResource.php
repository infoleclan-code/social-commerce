<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use App\Support\MediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A profile header: identity, reel and friend counts, and how the viewer relates to them. */
final class UserProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        return [
            'id' => $user->id,
            'username' => $user->username,
            'display_name' => $user->display_name,
            'avatar_url' => app(MediaUrl::class)->for($user->avatar_path),
            'bio' => $user->bio,
            'counts' => [
                'posts' => (int) $user->getAttribute('posts_count'),
                'followers' => (int) $user->getAttribute('followers_count'),
                'following' => (int) $user->getAttribute('following_count'),
            ],
            'viewer' => [
                'is_self' => (bool) $user->getAttribute('is_self'),
                'is_friend' => (bool) $user->getAttribute('is_friend'),
            ],
        ];
    }
}

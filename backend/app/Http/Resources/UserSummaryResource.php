<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use App\Support\MediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Public identity shown next to posts and comments. Nothing else about the user leaves the API here. */
final class UserSummaryResource extends JsonResource
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
        ];
    }
}

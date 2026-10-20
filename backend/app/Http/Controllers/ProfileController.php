<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ProfilePostsRequest;
use App\Http\Resources\FeedPostResource;
use App\Http\Resources\UserProfileResource;
use App\Services\Profile\ProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Profiles and the reel grid on them.
 *
 *   GET /api/v1/me
 *   GET /api/v1/users/{user}
 *   GET /api/v1/users/{user}/posts
 */
final class ProfileController extends Controller
{
    public function __construct(private readonly ProfileService $profiles) {}

    public function me(Request $request): JsonResponse
    {
        return $this->show($request, (string) $request->user()->getKey());
    }

    public function show(Request $request, string $user): JsonResponse
    {
        $profile = $this->profiles->show($request->user(), $user);

        return (new UserProfileResource($profile))->response();
    }

    public function posts(ProfilePostsRequest $request, string $user): JsonResponse
    {
        $page = $this->profiles->posts($request->user(), $user, $request->cursor(), $request->limit());

        return FeedPostResource::collection($page->posts)
            ->additional(['meta' => ['next_cursor' => $page->nextCursor]])
            ->response();
    }
}

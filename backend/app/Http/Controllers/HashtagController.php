<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Requests\HashtagPostsRequest;
use App\Http\Resources\FeedPostResource;
use App\Services\Feed\HashtagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 *   GET /api/v1/hashtags?q=           prefix search
 *   GET /api/v1/hashtags/trending     topics from recent public reels
 *   GET /api/v1/hashtags/posts?tag=   reels that carry that hashtag
 */
final class HashtagController extends Controller
{
    public function __construct(private readonly HashtagService $hashtags) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['q' => ['sometimes', 'nullable', 'string', 'max:41']]);

        return new JsonResponse(['data' => $this->hashtags->search((string) $request->query('q', ''))]);
    }

    public function trending(): JsonResponse
    {
        return new JsonResponse(['data' => $this->hashtags->trending()]);
    }

    public function posts(HashtagPostsRequest $request): JsonResponse
    {
        $tag = $request->tag();
        if ($tag === null) {
            throw ApiException::hashtagInvalid();
        }

        $page = $this->hashtags->posts($request->user(), $tag, $request->cursor(), $request->limit());

        FeedPostResource::warm($request, $page->posts);

        return FeedPostResource::collection($page->posts)
            ->additional(['meta' => ['next_cursor' => $page->nextCursor, 'tag' => $tag]])
            ->response();
    }
}

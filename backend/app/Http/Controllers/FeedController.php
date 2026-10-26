<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\FeedIndexRequest;
use App\Http\Requests\SharePostRequest;
use App\Http\Requests\StorePostRequest;
use App\Http\Resources\FeedPostResource;
use App\Services\Feed\FeedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Reels-style feed.
 *
 *   GET    /api/v1/feed?mode=for_you|following&cursor=&limit=   cursor-paginated posts
 *   POST   /api/v1/feed                                         create a media post (multipart)
 *   DELETE /api/v1/feed/{post}                                  delete own post
 *   POST   /api/v1/feed/{post}/like  |  DELETE .../like          idempotent like / unlike
 *   POST   /api/v1/feed/{post}/share                             count a share, get the link
 */
final class FeedController extends Controller
{
    public function __construct(private readonly FeedService $feed) {}

    public function index(FeedIndexRequest $request): JsonResponse
    {
        $page = $this->feed->page($request->user(), $request->mode(), $request->cursor(), $request->limit());
        FeedPostResource::warm($request, $page->posts);

        return FeedPostResource::collection($page->posts)
            ->additional(['meta' => ['next_cursor' => $page->nextCursor, 'mode' => $request->mode()]])
            ->response();
    }

    public function store(StorePostRequest $request): JsonResponse
    {
        $post = $this->feed->createMediaPost($request->user(), $request->toInput());

        return (new FeedPostResource($post))->response()->setStatusCode(201);
    }

    public function destroy(Request $request, string $post): JsonResponse
    {
        $this->feed->deletePost($request->user(), $post);

        return new JsonResponse(null, 204);
    }

    public function like(Request $request, string $post): JsonResponse
    {
        return new JsonResponse(['data' => $this->feed->like($request->user(), $post)]);
    }

    public function unlike(Request $request, string $post): JsonResponse
    {
        return new JsonResponse(['data' => $this->feed->unlike($request->user(), $post)]);
    }

    public function share(SharePostRequest $request, string $post): JsonResponse
    {
        return new JsonResponse(['data' => $this->feed->share($request->user(), $post, $request->channel())]);
    }

    public function engagement(Request $request, string $post): JsonResponse
    {
        return new JsonResponse(['data' => $this->feed->engagement($request->user(), $post)]);
    }
}

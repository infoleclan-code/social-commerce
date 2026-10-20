<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\CommentRequest;
use App\Http\Resources\FeedCommentResource;
use App\Services\Feed\CommentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 *   GET    /api/v1/feed/{post}/comments?parent_id=&cursor=   oldest first, one level of replies
 *   POST   /api/v1/feed/{post}/comments                      { body, parent_id? }
 *   DELETE /api/v1/comments/{comment}                        author or post owner
 */
final class FeedCommentController extends Controller
{
    public function __construct(private readonly CommentService $comments) {}

    public function index(CommentRequest $request, string $post): JsonResponse
    {
        $page = $this->comments->list(
            $request->user(),
            $post,
            $request->parentId(),
            $request->validated('cursor'),
            (int) $request->validated('limit', (int) config('feed.comments.page_size')),
        );

        return FeedCommentResource::collection($page['comments'])
            ->additional(['meta' => ['next_cursor' => $page['next_cursor'], 'comments_count' => $page['comments_count']]])
            ->response();
    }

    public function store(CommentRequest $request, string $post): JsonResponse
    {
        $comment = $this->comments->create($request->user(), $post, (string) $request->validated('body'), $request->parentId());

        return (new FeedCommentResource($comment))
            ->additional(['meta' => ['comments_count' => $this->comments->countForPost($comment->post_id)]])
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(Request $request, string $comment): JsonResponse
    {
        $postId = $this->comments->delete($request->user(), $comment);

        return new JsonResponse(['data' => ['comments_count' => $this->comments->countForPost($postId)]]);
    }
}

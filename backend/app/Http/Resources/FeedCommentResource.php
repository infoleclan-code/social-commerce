<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\FeedPostComment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class FeedCommentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var FeedPostComment $comment */
        $comment = $this->resource;

        return [
            'id' => $comment->id,
            'post_id' => $comment->post_id,
            'parent_id' => $comment->parent_id,
            'body' => $comment->body,
            'author' => new UserSummaryResource($comment->user),
            'replies_count' => (int) ($comment->getAttributes()['replies_count'] ?? 0),
            'is_mine' => $comment->user_id === $request->user()?->getKey(),
            'created_at' => $comment->created_at?->toIso8601ZuluString('millisecond'),
        ];
    }
}

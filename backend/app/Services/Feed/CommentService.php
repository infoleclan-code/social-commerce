<?php

declare(strict_types=1);

namespace App\Services\Feed;

use App\Exceptions\ApiException;
use App\Models\FeedPostComment;
use App\Models\User;
use App\Services\Commerce\RewardService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

/**
 * Comments on posts the viewer can see. Threads are one level deep: top-level comments oldest first,
 * each with its first replies; deeper pages of replies use the same endpoint with parent_id.
 * Posting needs the social opt-in (comments carry the username); the database enforces both rules.
 */
final readonly class CommentService
{
    public function __construct(private FeedService $feed, private RewardService $rewards) {}

    /** @return array{comments: Collection<int, FeedPostComment>, next_cursor: ?string} */
    public function list(User $viewer, string $postId, ?string $parentId, ?string $cursor, int $limit): array
    {
        $post = $this->feed->visiblePost($viewer, $postId);
        $position = $cursor === null ? null : FeedCursor::decode($cursor);
        if ($position !== null && $position->time === null) {
            throw ApiException::invalidCursor();
        }

        $comments = FeedPostComment::query()
            ->where('post_id', $post->id)
            ->when($parentId === null, fn (Builder $top) => $top->whereNull('parent_id'), fn (Builder $replies) => $replies->where('parent_id', $parentId))
            ->whereNotIn('user_id', function ($blocked) use ($viewer): void {
                $id = $viewer->getKey();
                $blocked->selectRaw('CASE WHEN requester_id = ? THEN addressee_id ELSE requester_id END', [$id])
                    ->from('social_connections')
                    ->where('status', 'blocked')
                    ->where(fn ($pair) => $pair->where('requester_id', $id)->orWhere('addressee_id', $id));
            })
            ->when($position !== null, function (Builder $after) use ($position): void {
                $time = $position->time->format('Y-m-d H:i:s.uP');
                $after->where(fn (Builder $newer) => $newer->where('created_at', '>', $time)
                    ->orWhere(fn (Builder $tie) => $tie->where('created_at', '=', $time)->where('id', '>', $position->id)));
            })
            ->with('user:id,username,display_name,avatar_path')
            ->withCount('replies')
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();

        $page = $comments->take($limit)->values();
        $last = $page->last();

        return [
            'comments' => $page,
            'next_cursor' => $comments->count() > $limit && $last !== null
                ? FeedCursor::forTime(CarbonImmutable::instance($last->created_at), $last->id)
                : null,
            'comments_count' => (int) $post->comments_count,
        ];
    }

    public function create(User $author, string $postId, string $body, ?string $parentId): FeedPostComment
    {
        if (! $author->social_feed_enabled) {
            throw ApiException::socialOptInRequired();
        }
        $post = $this->feed->visiblePost($author, $postId);

        if ($parentId !== null) {
            $parent = FeedPostComment::query()->whereKey($parentId)->where('post_id', $post->id)->first(['id', 'parent_id']);
            if ($parent === null) {
                throw ApiException::commentNotAllowed('The comment you are replying to does not exist.');
            }
            if ($parent->parent_id !== null) {
                throw ApiException::commentNotAllowed('Reply to the original comment instead.');
            }
        }

        $comment = (new FeedPostComment(['body' => trim($body), 'parent_id' => $parentId]))
            ->forceFill(['post_id' => $post->id, 'user_id' => $author->getKey()]);

        try {
            // Savepoint: a refused insert must not poison a surrounding transaction.
            DB::transaction(fn () => $comment->save());
        } catch (QueryException $exception) {
            $message = $exception->getMessage();
            if (str_contains($message, 'has not opted in to the social feed')) {
                throw ApiException::socialOptInRequired();
            }
            if (str_contains($message, 'Replies are one level deep')) {
                throw ApiException::commentNotAllowed('Reply to the original comment instead.');
            }
            throw $exception;
        }

        $comment->load('user:id,username,display_name,avatar_path')->loadCount('replies');
        $this->rewards->grant($author, 'comment', $post->id, 8, 4, 0);

        return $comment;
    }

    /** @return string the post id, so the caller can read the trigger-maintained comments_count */
    public function delete(User $actor, string $commentId): string
    {
        $comment = FeedPostComment::query()->with('post:id,user_id')->find($commentId);
        if ($comment === null || ($comment->user_id !== $actor->getKey() && $comment->post?->user_id !== $actor->getKey())) {
            throw ApiException::postNotFound();
        }
        $comment->delete();

        return $comment->post_id;
    }

    public function countForPost(string $postId): int
    {
        return $this->feed->commentsCount($postId);
    }
}

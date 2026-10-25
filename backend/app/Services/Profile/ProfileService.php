<?php

declare(strict_types=1);

namespace App\Services\Profile;

use App\Enums\ConnectionStatus;
use App\Enums\FeedPostKind;
use App\Enums\Visibility;
use App\Exceptions\ApiException;
use App\Models\FeedPost;
use App\Models\SocialConnection;
use App\Models\User;
use App\Services\Feed\FeedCursor;
use App\Services\Feed\FeedPage;
use App\Services\Social\SocialGraphService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * A public profile and the reels on it. Counts and the grid use the same visibility
 * rules as the feed: blocked pairs are hidden, friends-only posts stay with friends.
 */
final readonly class ProfileService
{
    public function show(User $viewer, string $userId): User
    {
        $user = $this->visibleUser($viewer, $userId);
        $social = app(SocialGraphService::class);
        $user->setAttribute('posts_count', $this->visiblePosts($viewer, $user)->count());
        $user->setAttribute('followers_count', $social->followerCount($user));
        $user->setAttribute('following_count', $social->followingCount($user));
        $user->setAttribute('is_self', $user->is($viewer));
        $user->setAttribute('is_friend', ! $user->is_self && $this->isFriend($viewer, $user));
        $user->setAttribute('is_following', $social->follows($viewer, $user));
        $user->setAttribute('connection', $social->label($viewer, $user));

        return $user;
    }

    public function posts(User $viewer, string $userId, ?string $cursor, int $limit): FeedPage
    {
        $author = $this->visibleUser($viewer, $userId);
        $position = $cursor === null ? null : FeedCursor::decode($cursor);
        if ($position !== null && $position->time === null) {
            throw ApiException::invalidCursor();
        }

        $query = $this->visiblePosts($viewer, $author)
            ->when($position !== null, function ($after) use ($position): void {
                $time = $position->time->format('Y-m-d H:i:s.uP');
                $after->where(fn ($older) => $older->where('feed_posts.created_at', '<', $time)
                    ->orWhere(fn ($tie) => $tie->where('feed_posts.created_at', '=', $time)->where('feed_posts.id', '<', $position->id)));
            })
            ->orderByDesc('feed_posts.created_at')
            ->orderByDesc('feed_posts.id');

        $posts = $query
            ->withViewerState($viewer)
            ->with(['user:id,username,display_name,avatar_path', 'shopTag.product.liveDrops'])
            ->limit($limit + 1)
            ->get();

        $page = $posts->take($limit)->values();
        $last = $page->last();

        return new FeedPage(
            $page,
            $posts->count() > $limit && $last !== null
                ? FeedCursor::forTime(CarbonImmutable::instance($last->created_at), $last->id)
                : null,
        );
    }

    private function visibleUser(User $viewer, string $userId): User
    {
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $userId)) {
            throw ApiException::profileNotFound();
        }

        $user = User::query()->whereKey($userId)->first();
        if ($user === null || $this->blocked($viewer, $user) || ! $this->profileVisible($viewer, $user)) {
            throw ApiException::profileNotFound();
        }

        return $user;
    }

    private function profileVisible(User $viewer, User $user): bool
    {
        if ($viewer->is($user)) {
            return true;
        }
        $visibility = $user->profile_visibility instanceof Visibility ? $user->profile_visibility : Visibility::Private;

        return match ($visibility) {
            Visibility::Public => true,
            Visibility::Friends => $this->isFriend($viewer, $user),
            Visibility::Private => false,
        };
    }

    /** @return Builder<FeedPost> */
    private function visiblePosts(User $viewer, User $author)
    {
        return FeedPost::query()
            ->where('feed_posts.user_id', $author->getKey())
            ->where('feed_posts.kind', FeedPostKind::Media->value)
            ->visibleTo($viewer);
    }

    private function isFriend(User $viewer, User $author): bool
    {
        return SocialConnection::between($viewer, $author)?->status === ConnectionStatus::Accepted;
    }

    private function blocked(User $viewer, User $author): bool
    {
        if ($viewer->is($author)) {
            return false;
        }

        $viewerId = $viewer->getKey();
        $authorId = $author->getKey();

        return DB::table('social_connections')
            ->where('status', 'blocked')
            ->whereRaw('LEAST(requester_id, addressee_id) = LEAST(?::uuid, ?::uuid)', [$viewerId, $authorId])
            ->whereRaw('GREATEST(requester_id, addressee_id) = GREATEST(?::uuid, ?::uuid)', [$viewerId, $authorId])
            ->exists();
    }
}

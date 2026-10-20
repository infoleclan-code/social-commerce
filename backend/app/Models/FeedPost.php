<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FeedAudience;
use App\Enums\FeedPostKind;
use App\Enums\MediaType;
use App\Models\Concerns\PostgresModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A reel. Exists only because the user explicitly posted it. The database
 * refuses inserts while users.social_feed_enabled is false.
 *
 * likes_count, comments_count, and rank_score are maintained by triggers.
 * Do not write those columns from PHP. shares_count is incremented by the
 * share action, which fires the rank trigger.
 */
class FeedPost extends Model
{
    use PostgresModel;
    use SoftDeletes;

    protected $fillable = ['caption', 'audience', 'tags'];

    protected $attributes = [
        'tags' => '[]',
    ];

    protected function casts(): array
    {
        return [
            'kind' => FeedPostKind::class,
            'audience' => FeedAudience::class,
            'media_type' => MediaType::class,
            'tags' => 'array',
            'media_width' => 'integer',
            'media_height' => 'integer',
            'media_duration_ms' => 'integer',
            'likes_count' => 'integer',
            'comments_count' => 'integer',
            'shares_count' => 'integer',
            'rank_score' => 'float',
            'liked_by_me' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Users who liked the post. Private: expose counts and the viewer's own flag only. */
    public function likers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'feed_post_likes', 'post_id', 'user_id')
            ->withPivot('created_at');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(FeedPostComment::class, 'post_id');
    }

    public function shopTag(): HasOne
    {
        return $this->hasOne(ReelProductTag::class, 'post_id');
    }

    /**
     * Posts $viewer may see: public posts, plus friends-audience posts of accepted friends and the
     * viewer's own posts, minus anything from a blocked pair.
     *
     * @param  Builder<FeedPost>  $query
     */
    public function scopeVisibleTo(Builder $query, User $viewer): void
    {
        $viewerId = $viewer->getKey();

        $query->where(function (Builder $visible) use ($viewerId): void {
            $visible->where('feed_posts.audience', FeedAudience::Public->value)
                ->orWhere('feed_posts.user_id', $viewerId)
                ->orWhere(function (Builder $friends) use ($viewerId): void {
                    $friends->where('feed_posts.audience', FeedAudience::Friends->value)
                        ->whereExists(function ($connection) use ($viewerId): void {
                            $connection->selectRaw('1')
                                ->from('social_connections as sc')
                                ->where('sc.status', 'accepted')
                                ->whereRaw('LEAST(sc.requester_id, sc.addressee_id) = LEAST(feed_posts.user_id, ?::uuid)', [$viewerId])
                                ->whereRaw('GREATEST(sc.requester_id, sc.addressee_id) = GREATEST(feed_posts.user_id, ?::uuid)', [$viewerId]);
                        });
                });
        })->withoutBlockedFor($viewer);
    }

    /**
     * Excludes posts by users in a blocked pair with $viewer (either side blocked).
     *
     * @param  Builder<FeedPost>  $query
     */
    public function scopeWithoutBlockedFor(Builder $query, User $viewer): void
    {
        $viewerId = $viewer->getKey();

        $query->whereNotExists(function ($blocked) use ($viewerId): void {
            $blocked->selectRaw('1')
                ->from('social_connections as sb')
                ->where('sb.status', 'blocked')
                ->whereRaw('LEAST(sb.requester_id, sb.addressee_id) = LEAST(feed_posts.user_id, ?::uuid)', [$viewerId])
                ->whereRaw('GREATEST(sb.requester_id, sb.addressee_id) = GREATEST(feed_posts.user_id, ?::uuid)', [$viewerId]);
        });
    }

    /**
     * Adds liked_by_me (bool) for $viewer without loading who liked.
     *
     * @param  Builder<FeedPost>  $query
     */
    public function scopeWithViewerState(Builder $query, User $viewer): void
    {
        if ($query->getQuery()->columns === null) {
            $query->select('feed_posts.*');
        }
        $query->selectRaw(
            'EXISTS (SELECT 1 FROM feed_post_likes l WHERE l.post_id = feed_posts.id AND l.user_id = ?) AS liked_by_me',
            [$viewer->getKey()],
        );
    }
}

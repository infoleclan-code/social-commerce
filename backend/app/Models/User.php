<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ConnectionStatus;
use App\Enums\RiskStatus;
use App\Enums\Visibility;
use App\Models\Concerns\PostgresModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Account. Erasure is DB::select('SELECT anonymize_user(?)', [$id]).
 *
 * Serialization is privacy-first: only the public profile is visible by
 * default. Expose anything else through an explicit API Resource.
 */
class User extends Authenticatable
{
    use HasApiTokens;
    use Notifiable;
    use PostgresModel;
    use SoftDeletes;

    /** User-editable fields only. Risk status and the invite tree are set by services via forceFill(). */
    protected $fillable = [
        'email',
        'password',
        'username',
        'display_name',
        'avatar_path',
        'bio',
        'locale',
        'country_code',
        'timezone',
        'social_feed_enabled',
        'profile_visibility',
        'settings',
    ];

    /** Public profile only (whitelist, so new columns never leak by default). */
    protected $visible = ['id', 'username', 'display_name', 'avatar_path'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'social_feed_enabled' => 'boolean',
            'profile_visibility' => Visibility::class,
            'risk_status' => RiskStatus::class,
            'last_active_at' => 'datetime',
            'anonymized_at' => 'datetime',
            'settings' => 'array',
            'social_score' => 'integer',
        ];
    }

    /** Who invited this user into the app. */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_by_user_id');
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(User::class, 'referred_by_user_id');
    }

    public function devices(): HasMany
    {
        return $this->hasMany(UserDevice::class);
    }

    public function consents(): HasMany
    {
        return $this->hasMany(UserConsent::class);
    }

    public function sentConnections(): HasMany
    {
        return $this->hasMany(SocialConnection::class, 'requester_id');
    }

    public function receivedConnections(): HasMany
    {
        return $this->hasMany(SocialConnection::class, 'addressee_id');
    }

    /** Accepted friends where this user sent the request (one direction of the pair). */
    public function friendsRequested(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'social_connections', 'requester_id', 'addressee_id')
            ->wherePivot('status', ConnectionStatus::Accepted->value)
            ->withPivot(['id', 'source', 'responded_at'])
            ->withTimestamps();
    }

    /** Accepted friends where this user received the request (the other direction). */
    public function friendsAccepted(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'social_connections', 'addressee_id', 'requester_id')
            ->wherePivot('status', ConnectionStatus::Accepted->value)
            ->withPivot(['id', 'source', 'responded_at'])
            ->withTimestamps();
    }

    /**
     * All accepted friends, both directions, as one query.
     */
    public function friends(): Builder
    {
        $id = $this->getKey();

        return User::query()->whereIn('id', function ($query) use ($id): void {
            $query->selectRaw('CASE WHEN requester_id = ? THEN addressee_id ELSE requester_id END', [$id])
                ->from('social_connections')
                ->where('status', ConnectionStatus::Accepted->value)
                ->where(fn ($q) => $q->where('requester_id', $id)->orWhere('addressee_id', $id));
        });
    }

    public function feedPosts(): HasMany
    {
        return $this->hasMany(FeedPost::class);
    }

    /** Posts this user liked. The liker list is private to the user. */
    public function likedPosts(): BelongsToMany
    {
        return $this->belongsToMany(FeedPost::class, 'feed_post_likes', 'user_id', 'post_id')
            ->withPivot('created_at');
    }

    public function feedComments(): HasMany
    {
        return $this->hasMany(FeedPostComment::class);
    }

    public function isAnonymized(): bool
    {
        return $this->anonymized_at !== null;
    }
}

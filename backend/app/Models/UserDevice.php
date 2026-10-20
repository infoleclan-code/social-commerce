<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DevicePlatform;
use App\Models\Concerns\PostgresModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One installed app. Push only to pushable() devices; revoke on logout. */
class UserDevice extends Model
{
    use PostgresModel;

    protected $fillable = ['platform', 'expo_push_token', 'push_enabled', 'app_version'];

    protected $hidden = ['expo_push_token', 'device_fingerprint_hash'];

    protected function casts(): array
    {
        return [
            'platform' => DevicePlatform::class,
            'push_enabled' => 'boolean',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopePushable(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')->where('push_enabled', true)->whereNotNull('expo_push_token');
    }
}

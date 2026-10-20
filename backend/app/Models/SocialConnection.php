<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ConnectionSource;
use App\Enums\ConnectionStatus;
use App\Models\Concerns\HasStatusTransitions;
use App\Models\Concerns\PostgresModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Friendship / block between two users. Exactly one row per unordered pair
 * (functional unique index), so always look it up with between().
 */
class SocialConnection extends Model
{
    use HasStatusTransitions;
    use PostgresModel;

    protected $fillable = ['addressee_id', 'source', 'invite_code_used'];

    protected function casts(): array
    {
        return [
            'status' => ConnectionStatus::class,
            'source' => ConnectionSource::class,
            'responded_at' => 'datetime',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function addressee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'addressee_id');
    }

    public function blockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocked_by_user_id');
    }

    public static function between(User $a, User $b): ?self
    {
        return static::query()
            ->where(fn ($q) => $q->where('requester_id', $a->getKey())->where('addressee_id', $b->getKey()))
            ->orWhere(fn ($q) => $q->where('requester_id', $b->getKey())->where('addressee_id', $a->getKey()))
            ->first();
    }
}

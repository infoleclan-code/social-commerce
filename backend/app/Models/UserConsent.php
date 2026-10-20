<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ConsentPurpose;
use App\Enums\ConsentSource;
use App\Models\Concerns\PostgresModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only consent log (GDPR Art. 7(1)). Withdrawal = a new row with
 * granted=false; the DB rejects UPDATEs. Current state = newest row per purpose:
 *   UserConsent::latestFor($user, ConsentPurpose::MarketingPush)?->granted
 */
class UserConsent extends Model
{
    use PostgresModel;

    public const UPDATED_AT = null;

    protected $fillable = ['purpose', 'granted', 'policy_version', 'source'];

    protected function casts(): array
    {
        return [
            'purpose' => ConsentPurpose::class,
            'source' => ConsentSource::class,
            'granted' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function latestFor(User $user, ConsentPurpose $purpose): ?self
    {
        return static::query()
            ->where('user_id', $user->getKey())
            ->where('purpose', $purpose->value)
            ->latest('created_at')
            ->first();
    }

    public static function isGranted(User $user, ConsentPurpose $purpose): bool
    {
        return static::latestFor($user, $purpose)?->granted ?? false;
    }
}

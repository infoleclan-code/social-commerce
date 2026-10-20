<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PostgresModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReferralLink extends Model
{
    use PostgresModel;

    public const UPDATED_AT = null;

    protected $fillable = ['token', 'user_id', 'post_id', 'product_id', 'commission_bps'];

    public function clicks(): HasMany
    {
        return $this->hasMany(ReferralClick::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(CommissionEntry::class);
    }
}

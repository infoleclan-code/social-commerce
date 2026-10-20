<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PostgresModel;
use Illuminate\Database\Eloquent\Model;

class PrizeSpin extends Model
{
    use PostgresModel;

    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'day', 'coupon_id', 'discount_bps'];

    protected function casts(): array
    {
        return ['discount_bps' => 'integer'];
    }
}

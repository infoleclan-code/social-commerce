<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PostgresModel;
use Illuminate\Database\Eloquent\Model;

class Gift extends Model
{
    use PostgresModel;

    public const UPDATED_AT = null;

    protected $fillable = ['buyer_id', 'product_id', 'post_id', 'order_id', 'token', 'status', 'claimed_by', 'expires_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'claimed_at' => 'datetime'];
    }
}

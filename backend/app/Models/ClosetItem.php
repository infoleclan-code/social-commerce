<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PostgresModel;
use Illuminate\Database\Eloquent\Model;

class ClosetItem extends Model
{
    use PostgresModel;

    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'order_id', 'product_id', 'status', 'listing_price_minor', 'listed_at'];

    protected function casts(): array
    {
        return ['listing_price_minor' => 'integer', 'listed_at' => 'datetime'];
    }
}

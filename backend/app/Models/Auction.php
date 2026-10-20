<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PostgresModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Auction extends Model
{
    use PostgresModel;

    public const UPDATED_AT = null;

    protected $fillable = ['product_id', 'start_minor', 'current_minor', 'leader_id', 'ends_at'];

    protected function casts(): array
    {
        return [
            'start_minor' => 'integer',
            'current_minor' => 'integer',
            'ends_at' => 'datetime',
        ];
    }

    public function bids(): HasMany
    {
        return $this->hasMany(AuctionBid::class);
    }
}

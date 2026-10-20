<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PostgresModel;
use Illuminate\Database\Eloquent\Model;

class AuctionBid extends Model
{
    use PostgresModel;

    public const UPDATED_AT = null;

    protected $fillable = ['auction_id', 'bidder_id', 'amount_minor'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer'];
    }
}

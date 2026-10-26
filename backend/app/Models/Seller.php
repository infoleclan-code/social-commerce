<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SellerStatus;
use App\Models\Concerns\HasStatusTransitions;
use App\Models\Concerns\PostgresModel;
use Illuminate\Database\Eloquent\Model;

/** Stripe Connect seller. status changes go through transitionTo(). */
class Seller extends Model
{
    use HasStatusTransitions;
    use PostgresModel;

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'status' => SellerStatus::class,
            'charges_enabled' => 'boolean',
            'payouts_enabled' => 'boolean',
        ];
    }
}

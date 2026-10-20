<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PostgresModel;
use Illuminate\Database\Eloquent\Model;

class DropReservation extends Model
{
    use PostgresModel;

    public const UPDATED_AT = null;

    protected $fillable = ['drop_hour_id', 'user_id', 'quantity', 'status', 'expires_at'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'expires_at' => 'datetime',
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PostgresModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DropHour extends Model
{
    use PostgresModel;

    public const UPDATED_AT = null;

    protected $fillable = ['product_id', 'price_minor', 'stock', 'reserved', 'starts_at', 'ends_at'];

    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'stock' => 'integer',
            'reserved' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(DropReservation::class);
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PostgresModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAuthorization extends Model
{
    use PostgresModel;

    public const UPDATED_AT = null;

    protected $fillable = [
        'buyer_id', 'product_id', 'order_id', 'squad_id', 'purpose', 'status',
        'authorized_minor', 'captured_minor', 'currency', 'idempotency_key', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'authorized_minor' => 'integer',
            'captured_minor' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ListingStatus;
use App\Models\Concerns\PostgresModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use PostgresModel;

    protected $fillable = ['title', 'brand', 'image_url', 'price_minor', 'squad_price_minor', 'slug', 'currency', 'stock', 'try_on_enabled'];

    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'squad_price_minor' => 'integer',
            'stock' => 'integer',
            'try_on_enabled' => 'boolean',
            'listing_status' => ListingStatus::class,
        ];
    }

    public function liveDrops(): HasMany
    {
        return $this->hasMany(FlashDrop::class, 'product_id')
            ->where('stock_remaining', '>', 0)
            ->where('ends_at', '>', now());
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PostgresModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReelProductTag extends Model
{
    use PostgresModel;

    public $incrementing = false;

    protected $primaryKey = 'post_id';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'commission_bps' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(FeedPost::class, 'post_id');
    }
}

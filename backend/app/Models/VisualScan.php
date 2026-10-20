<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PostgresModel;
use Illuminate\Database\Eloquent\Model;

class VisualScan extends Model
{
    use PostgresModel;

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'image_sha256', 'analyzer', 'detected_category', 'labels',
        'bounding_box', 'matched_product_ids', 'fallback', 'latency_ms',
    ];

    protected function casts(): array
    {
        return [
            'labels' => 'array',
            'bounding_box' => 'array',
            'fallback' => 'boolean',
            'latency_ms' => 'integer',
        ];
    }
}

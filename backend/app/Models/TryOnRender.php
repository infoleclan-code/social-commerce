<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PostgresModel;
use Illuminate\Database\Eloquent\Model;

class TryOnRender extends Model
{
    use PostgresModel;

    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'product_id', 'photo_sha256', 'renderer', 'image_url'];
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PostgresModel;
use Illuminate\Database\Eloquent\Model;

class ShopRoom extends Model
{
    use PostgresModel;

    public const UPDATED_AT = null;

    protected $fillable = ['host_id', 'post_id', 'token', 'visibility', 'ends_at'];
}

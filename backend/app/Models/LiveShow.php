<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PostgresModel;
use Illuminate\Database\Eloquent\Model;

class LiveShow extends Model
{
    use PostgresModel;

    public const UPDATED_AT = null;

    protected $fillable = ['host_id', 'title', 'category', 'region', 'starts_at', 'ends_at'];
}

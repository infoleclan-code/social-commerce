<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PostgresModel;
use Illuminate\Database\Eloquent\Model;

class Place extends Model
{
    use PostgresModel;

    public const UPDATED_AT = null;

    public $timestamps = false;

    protected $fillable = ['name', 'kind', 'member_goal', 'unlocked_at'];
}

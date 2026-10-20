<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PostgresModel;
use Illuminate\Database\Eloquent\Model;

class LiveActivity extends Model
{
    use PostgresModel;

    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'kind', 'subject_id', 'title', 'body', 'action', 'ends_at'];
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PostgresModel;
use Illuminate\Database\Eloquent\Model;

class ReferralClick extends Model
{
    use PostgresModel;

    public const UPDATED_AT = null;

    protected $fillable = ['referral_link_id', 'viewer_id'];
}

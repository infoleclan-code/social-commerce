<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PostgresModel;
use Illuminate\Database\Eloquent\Model;

class CommissionEntry extends Model
{
    use PostgresModel;

    public const UPDATED_AT = null;

    protected $table = 'commission_ledger';

    protected $fillable = ['user_id', 'referral_link_id', 'order_id', 'entry', 'amount_minor', 'currency'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer'];
    }
}

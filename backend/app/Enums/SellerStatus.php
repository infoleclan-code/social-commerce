<?php

declare(strict_types=1);

namespace App\Enums;

/** Mirrors the CHECK constraint on sellers.status. */
enum SellerStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';
}

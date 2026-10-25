<?php

declare(strict_types=1);

namespace App\Enums;

/** Mirrors the CHECK constraint on products.listing_status. */
enum ListingStatus: string
{
    case Active = 'active';
    case Review = 'review';
    case Blocked = 'blocked';
}

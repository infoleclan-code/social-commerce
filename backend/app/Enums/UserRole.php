<?php

declare(strict_types=1);

namespace App\Enums;

/** Mirrors the CHECK constraint on users.role. */
enum UserRole: string
{
    case Member = 'member';
    case Admin = 'admin';
}

<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Mirrors the CHECK constraint on users.profile_visibility. Default is the most private value.
 * Keep values identical to the database; tests/Feature/SchemaParityTest.php compares them.
 */
enum Visibility: string
{
    case Private = 'private';
    case Friends = 'friends';
    case Public = 'public';
}

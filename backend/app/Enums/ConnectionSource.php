<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Mirrors the CHECK constraint on social_connections.source.
 * Keep values identical to the database; tests/Feature/SchemaParityTest.php compares them.
 */
enum ConnectionSource: string
{
    case InviteCode = 'invite_code';
    case Contacts = 'contacts';
    case Search = 'search';
    case Profile = 'profile';
}

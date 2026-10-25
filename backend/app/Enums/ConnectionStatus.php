<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Mirrors the CHECK constraint on social_connections.status (transitions guarded by status_transitions)
 * Keep values identical to the database; tests/Feature/SchemaParityTest.php compares them.
 */
enum ConnectionStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Blocked = 'blocked';
}

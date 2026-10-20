<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Mirrors the CHECK constraint on users.risk_status
 * Keep values identical to the database; tests/Feature/SchemaParityTest.php compares them.
 */
enum RiskStatus: string
{
    case Ok         = 'ok';
    case Review     = 'review';
    case Restricted = 'restricted';
    case Banned     = 'banned';
}

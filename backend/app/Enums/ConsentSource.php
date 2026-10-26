<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Mirrors the CHECK constraint on user_consents.source
 * Keep values identical to the database; tests/Feature/SchemaParityTest.php compares them.
 */
enum ConsentSource: string
{
    case Onboarding = 'onboarding';
    case Settings = 'settings';
    case Prompt = 'prompt';
    case Api = 'api';
}

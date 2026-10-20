<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Mirrors the CHECK constraint on user_devices.platform
 * Keep values identical to the database; tests/Feature/SchemaParityTest.php compares them.
 */
enum DevicePlatform: string
{
    case Ios     = 'ios';
    case Android = 'android';
    case Web     = 'web';
}

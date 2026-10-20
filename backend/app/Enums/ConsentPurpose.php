<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Mirrors the CHECK constraint on user_consents.purpose. Transactional notifications need no consent; these do.
 * Keep values identical to the database; tests/Feature/SchemaParityTest.php compares them.
 */
enum ConsentPurpose: string
{
    case MarketingPush    = 'marketing_push';
    case MarketingEmail   = 'marketing_email';
    case Personalization  = 'personalization';
    case Analytics        = 'analytics';
    case ContactsMatching = 'contacts_matching';
}

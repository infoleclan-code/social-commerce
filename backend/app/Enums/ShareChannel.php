<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Mirrors the CHECK constraint on share_links.channel
 * Keep values identical to the database; tests/Feature/SchemaParityTest.php compares them.
 */
enum ShareChannel: string
{
    case CopyLink  = 'copy_link';
    case Whatsapp  = 'whatsapp';
    case Instagram = 'instagram';
    case Tiktok    = 'tiktok';
    case Messenger = 'messenger';
    case Sms       = 'sms';
    case Other     = 'other';
}

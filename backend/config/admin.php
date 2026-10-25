<?php

declare(strict_types=1);

return [
    /*
     * A second factor for the seeded demo admin when no authenticator is available.
     * Empty in production. A real TOTP code always works when the admin has a secret.
     */
    'demo_otp' => env('ADMIN_DEMO_OTP', ''),
];

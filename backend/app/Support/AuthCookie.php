<?php

declare(strict_types=1);

namespace App\Support;

use Symfony\Component\HttpFoundation\Cookie;

/** HttpOnly session cookie for the web client. Native apps keep the bearer token. */
final class AuthCookie
{
    public static function make(string $token): Cookie
    {
        return cookie('auth_token', $token, 60 * 24 * 30, '/api', null, self::secure(), true, false, self::sameSite());
    }

    public static function forget(): Cookie
    {
        return cookie('auth_token', '', -2628000, '/api', null, self::secure(), true, false, self::sameSite());
    }

    private static function sameSite(): string
    {
        $sameSite = (string) config('auth.cookie_same_site', 'lax');

        return in_array($sameSite, ['lax', 'strict', 'none'], true) ? $sameSite : 'lax';
    }

    private static function secure(): bool
    {
        if (self::sameSite() === 'none') {
            return true;
        }

        return (bool) config('auth.cookie_secure', false);
    }
}

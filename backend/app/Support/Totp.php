<?php

declare(strict_types=1);

namespace App\Support;

/** RFC 6238 TOTP (SHA-1, 6 digits, 30 seconds). Secrets are base32. */
final class Totp
{
    public static function secret(): string
    {
        return self::base32(random_bytes(20));
    }

    public static function verify(string $secret, string $code, int $window = 1, ?int $now = null): bool
    {
        if (preg_match('/^[0-9]{6}$/', $code) !== 1) {
            return false;
        }
        $counter = intdiv($now ?? time(), 30);
        for ($offset = -$window; $offset <= $window; $offset++) {
            if (hash_equals(self::code($secret, $counter + $offset), $code)) {
                return true;
            }
        }

        return false;
    }

    public static function code(string $secret, int $counter): string
    {
        $hash = hash_hmac('sha1', pack('N*', 0).pack('N*', $counter), self::decode($secret), true);
        $offset = ord($hash[19]) & 0x0f;
        $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7fffffff;

        return str_pad((string) ($value % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    private static function base32(string $bytes): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($bytes) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }
            $out .= $alphabet[bindec($chunk)];
        }

        return $out;
    }

    private static function decode(string $secret): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = strtoupper(rtrim($secret, '='));
        $bits = '';
        foreach (str_split($secret) as $char) {
            $pos = strpos($alphabet, $char);
            if ($pos === false) {
                return '';
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $bytes = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr(bindec($chunk));
            }
        }

        return $bytes;
    }
};

<?php

declare(strict_types=1);

namespace App\Services\Compare;

use Illuminate\Http\Request;

/** Shop country and the Google Shopping gl/hl pair. The raw IP is never read or stored. */
final class Region
{
    /** @var array<string, array{currency: string, gl: string, hl: string, name: string}> */
    public const COUNTRIES = [
        'US' => ['currency' => 'USD', 'gl' => 'us', 'hl' => 'en', 'name' => 'United States'],
        'CA' => ['currency' => 'CAD', 'gl' => 'ca', 'hl' => 'en', 'name' => 'Canada'],
        'MX' => ['currency' => 'MXN', 'gl' => 'mx', 'hl' => 'es', 'name' => 'Mexico'],
        'BR' => ['currency' => 'BRL', 'gl' => 'br', 'hl' => 'pt', 'name' => 'Brazil'],
        'GB' => ['currency' => 'GBP', 'gl' => 'uk', 'hl' => 'en', 'name' => 'United Kingdom'],
        'IE' => ['currency' => 'EUR', 'gl' => 'ie', 'hl' => 'en', 'name' => 'Ireland'],
        'DE' => ['currency' => 'EUR', 'gl' => 'de', 'hl' => 'de', 'name' => 'Germany'],
        'FR' => ['currency' => 'EUR', 'gl' => 'fr', 'hl' => 'fr', 'name' => 'France'],
        'ES' => ['currency' => 'EUR', 'gl' => 'es', 'hl' => 'es', 'name' => 'Spain'],
        'IT' => ['currency' => 'EUR', 'gl' => 'it', 'hl' => 'it', 'name' => 'Italy'],
        'NL' => ['currency' => 'EUR', 'gl' => 'nl', 'hl' => 'nl', 'name' => 'Netherlands'],
        'BE' => ['currency' => 'EUR', 'gl' => 'be', 'hl' => 'nl', 'name' => 'Belgium'],
        'AT' => ['currency' => 'EUR', 'gl' => 'at', 'hl' => 'de', 'name' => 'Austria'],
        'PT' => ['currency' => 'EUR', 'gl' => 'pt', 'hl' => 'pt', 'name' => 'Portugal'],
        'SE' => ['currency' => 'SEK', 'gl' => 'se', 'hl' => 'sv', 'name' => 'Sweden'],
        'NO' => ['currency' => 'NOK', 'gl' => 'no', 'hl' => 'no', 'name' => 'Norway'],
        'DK' => ['currency' => 'DKK', 'gl' => 'dk', 'hl' => 'da', 'name' => 'Denmark'],
        'FI' => ['currency' => 'EUR', 'gl' => 'fi', 'hl' => 'fi', 'name' => 'Finland'],
        'PL' => ['currency' => 'PLN', 'gl' => 'pl', 'hl' => 'pl', 'name' => 'Poland'],
        'CH' => ['currency' => 'CHF', 'gl' => 'ch', 'hl' => 'de', 'name' => 'Switzerland'],
        'TR' => ['currency' => 'TRY', 'gl' => 'tr', 'hl' => 'tr', 'name' => 'Turkey'],
        'AE' => ['currency' => 'AED', 'gl' => 'ae', 'hl' => 'en', 'name' => 'United Arab Emirates'],
        'IN' => ['currency' => 'INR', 'gl' => 'in', 'hl' => 'en', 'name' => 'India'],
        'JP' => ['currency' => 'JPY', 'gl' => 'jp', 'hl' => 'ja', 'name' => 'Japan'],
        'KR' => ['currency' => 'KRW', 'gl' => 'kr', 'hl' => 'ko', 'name' => 'South Korea'],
        'SG' => ['currency' => 'SGD', 'gl' => 'sg', 'hl' => 'en', 'name' => 'Singapore'],
        'AU' => ['currency' => 'AUD', 'gl' => 'au', 'hl' => 'en', 'name' => 'Australia'],
        'NZ' => ['currency' => 'NZD', 'gl' => 'nz', 'hl' => 'en', 'name' => 'New Zealand'],
        'ZA' => ['currency' => 'ZAR', 'gl' => 'za', 'hl' => 'en', 'name' => 'South Africa'],
    ];

    public static function country(?string $value): ?string
    {
        $code = strtoupper(trim((string) $value));
        if (preg_match('/^[A-Z]{2}$/', $code) !== 1 || in_array($code, ['XX', 'T1'], true)) {
            return null;
        }

        return $code;
    }

    public static function currencyFor(string $country): string
    {
        return self::COUNTRIES[$country]['currency'] ?? 'USD';
    }

    public static function gl(string $country): string
    {
        return self::COUNTRIES[$country]['gl'] ?? strtolower($country);
    }

    public static function hl(string $country): string
    {
        return self::COUNTRIES[$country]['hl'] ?? 'en';
    }

    /** A country hint from a proxy header or Accept-Language. Not an IP address. */
    public static function detect(Request $request): string
    {
        foreach (['CF-IPCountry', 'CloudFront-Viewer-Country', 'X-Geo-Country'] as $header) {
            $code = self::country($request->header($header));
            if ($code !== null) {
                return $code;
            }
        }

        $language = (string) $request->header('Accept-Language', '');
        if (preg_match('/^[A-Za-z]{2,3}[-_]([A-Za-z]{2})/', $language, $match) === 1) {
            $code = self::country($match[1]);
            if ($code !== null) {
                return $code;
            }
        }

        return 'US';
    }

    /** @return list<array{code: string, name: string, currency: string}> */
    public static function choices(): array
    {
        $choices = [];
        foreach (self::COUNTRIES as $code => $row) {
            $choices[] = ['code' => $code, 'name' => $row['name'], 'currency' => $row['currency']];
        }

        return $choices;
    }
}

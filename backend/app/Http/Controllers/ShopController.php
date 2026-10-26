<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Compare\FxRates;
use App\Services\Compare\Region;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** The shopper's country and currency. Stored on the account, not on the public profile. */
final class ShopController extends Controller
{
    public function __construct(private readonly FxRates $fx) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $detected = Region::detect($request);
        $saved = Region::country($user->shop_country);
        $country = $saved ?? $detected;
        $currency = strtoupper((string) ($user->shop_currency ?: Region::currencyFor($country)));
        if (! $this->fx->supports($currency)) {
            $currency = 'USD';
        }

        return new JsonResponse(['data' => [
            'country' => $country,
            'currency' => $currency,
            'detected_country' => $detected,
            'detected_currency' => $this->fx->supports(Region::currencyFor($detected)) ? Region::currencyFor($detected) : 'USD',
            'source' => $saved !== null ? 'saved' : 'detected',
            'choices' => Region::choices(),
            'currencies' => array_keys($this->fx->table()),
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'country' => ['required', 'regex:/^[A-Za-z]{2}$/'],
            'currency' => ['required', 'regex:/^[A-Za-z]{3}$/'],
        ]);
        $country = Region::country($data['country']);
        if ($country === null || ! isset(Region::COUNTRIES[$country])) {
            throw ValidationException::withMessages(['country' => 'Choose a listed country.']);
        }
        $currency = strtoupper($data['currency']);
        if (! $this->fx->supports($currency)) {
            throw ValidationException::withMessages(['currency' => 'Choose a currency we have a rate for.']);
        }
        DB::table('users')->where('id', $request->user()->id)->update([
            'shop_country' => $country,
            'shop_currency' => $currency,
        ]);
        $request->user()->forceFill([
            'shop_country' => $country,
            'shop_currency' => $currency,
        ]);

        return $this->show($request);
    }
}

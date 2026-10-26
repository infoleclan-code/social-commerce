<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Assistant\ShoppingAssistant;
use App\Services\Compare\Alternatives;
use App\Services\Compare\FxRates;
use App\Services\Compare\LinkSearch;
use App\Services\Compare\PriceForecast;
use App\Services\Compare\Region;
use App\Services\Compare\SavingsTracker;
use App\Services\Compare\TryOnPreview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Assistant, forecasts, link search, try-on scope, alternatives, and savings. */
final class DiscoverController extends Controller
{
    public function __construct(
        private readonly FxRates $fx,
        private readonly ShoppingAssistant $assistant,
        private readonly PriceForecast $forecast,
        private readonly LinkSearch $links,
        private readonly TryOnPreview $tryOn,
        private readonly Alternatives $alternatives,
        private readonly SavingsTracker $savings,
    ) {}

    public function assistant(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'min:2', 'max:500'],
            'currency' => ['sometimes', 'regex:/^[A-Za-z]{3}$/'],
            'country' => ['sometimes', 'nullable', 'regex:/^[A-Za-z]{2}$/'],
        ]);

        return new JsonResponse(['data' => $this->assistant->ask($data['message'], $this->filters($request, $data))]);
    }

    public function forecast(Request $request): JsonResponse
    {
        $data = $request->validate([
            'brand' => ['required', 'string', 'min:1', 'max:80'],
            'model' => ['required', 'string', 'min:1', 'max:120'],
            'size' => ['sometimes', 'nullable', 'string', 'max:40'],
            'currency' => ['sometimes', 'regex:/^[A-Za-z]{3}$/'],
            'current_minor' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000'],
        ]);
        $currency = $this->currency($data['currency'] ?? 'USD');

        return new JsonResponse(['data' => $this->forecast->estimate(
            $data['brand'],
            $data['model'],
            $this->blank($data['size'] ?? null),
            $currency,
            isset($data['current_minor']) && $data['current_minor'] !== null ? (int) $data['current_minor'] : null,
        )]);
    }

    public function link(Request $request): JsonResponse
    {
        $data = $request->validate([
            'url' => ['required', 'string', 'max:500'],
            'currency' => ['sometimes', 'regex:/^[A-Za-z]{3}$/'],
            'country' => ['sometimes', 'nullable', 'regex:/^[A-Za-z]{2}$/'],
        ]);

        return new JsonResponse(['data' => $this->links->search($data['url'], $this->filters($request, $data))]);
    }

    public function tryOn(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category' => ['required', 'string', 'max:40'],
        ]);

        return new JsonResponse(['data' => $this->tryOn->describe($data['category'])]);
    }

    public function alternatives(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:120'],
            'currency' => ['sometimes', 'regex:/^[A-Za-z]{3}$/'],
            'country' => ['sometimes', 'nullable', 'regex:/^[A-Za-z]{2}$/'],
        ]);

        return new JsonResponse(['data' => $this->alternatives->suggest($data['q'], $this->filters($request, $data))]);
    }

    public function savings(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->savings->summary($request->user())]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{brand: string|null, size: string|null, min_minor: int|null, max_minor: int|null, in_stock: bool, sort: string, currency: string, country: string}
     */
    private function filters(Request $request, array $data): array
    {
        return [
            'brand' => null,
            'size' => null,
            'min_minor' => null,
            'max_minor' => null,
            'in_stock' => true,
            'sort' => 'total_asc',
            'currency' => $this->currency($data['currency'] ?? 'USD'),
            'country' => $this->country($request, $data['country'] ?? null),
        ];
    }

    private function country(Request $request, mixed $value): string
    {
        if (is_string($value) && trim($value) !== '') {
            $code = Region::country($value);
            if ($code === null) {
                throw ValidationException::withMessages(['country' => 'Choose a listed country.']);
            }

            return $code;
        }

        return Region::country($request->user()?->shop_country)
            ?? Region::detect($request);
    }

    private function currency(mixed $value): string
    {
        $currency = strtoupper((string) $value);
        if (! $this->fx->supports($currency)) {
            throw ValidationException::withMessages(['currency' => 'Choose a currency we have a rate for.']);
        }

        return $currency;
    }

    private function blank(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}

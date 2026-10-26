<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Compare\CompareService;
use App\Services\Compare\FxRates;
use App\Services\Compare\PriceHistory;
use App\Services\Compare\Region;
use App\Services\Compare\VisualSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Product search across external stores. The response is a plain array, never a model. */
final class CompareController extends Controller
{
    public function __construct(
        private readonly CompareService $compare,
        private readonly FxRates $fx,
        private readonly PriceHistory $history,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:120'],
            'brand' => ['sometimes', 'nullable', 'string', 'max:80'],
            'size' => ['sometimes', 'nullable', 'string', 'max:40'],
            'min_minor' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000'],
            'max_minor' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000'],
            'in_stock' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'in:total_asc,total_desc,price_asc'],
            'currency' => ['sometimes', 'regex:/^[A-Za-z]{3}$/'],
            'country' => ['sometimes', 'nullable', 'regex:/^[A-Za-z]{2}$/'],
        ]);

        return new JsonResponse(['data' => $this->compare->search($data['q'], $this->filters($request, $data))]);
    }

    public function history(Request $request): JsonResponse
    {
        $data = $request->validate([
            'brand' => ['required', 'string', 'min:1', 'max:80'],
            'model' => ['required', 'string', 'min:1', 'max:120'],
            'size' => ['sometimes', 'nullable', 'string', 'max:40'],
            'currency' => ['sometimes', 'regex:/^[A-Za-z]{3}$/'],
            'current_minor' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000'],
        ]);
        $currency = $this->currency($data['currency'] ?? 'USD');

        return new JsonResponse(['data' => $this->history->chart(
            $data['brand'],
            $data['model'],
            $this->blank($data['size'] ?? null),
            $currency,
            isset($data['current_minor']) && $data['current_minor'] !== null ? (int) $data['current_minor'] : null,
        )]);
    }

    public function visual(Request $request, VisualSearch $visual): JsonResponse
    {
        $data = $request->validate([
            'image' => ['required', 'file', 'max:500'],
            'brand' => ['sometimes', 'nullable', 'string', 'max:80'],
            'size' => ['sometimes', 'nullable', 'string', 'max:40'],
            'min_minor' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000'],
            'max_minor' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000'],
            'in_stock' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'in:total_asc,total_desc,price_asc'],
            'currency' => ['sometimes', 'regex:/^[A-Za-z]{3}$/'],
            'country' => ['sometimes', 'nullable', 'regex:/^[A-Za-z]{2}$/'],
        ]);
        $file = $request->file('image');
        $bytes = $file !== null ? (string) file_get_contents((string) $file->getRealPath()) : '';
        $mime = strtolower((string) ($file?->getMimeType() ?: 'application/octet-stream'));
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            $mime = match (strtolower((string) $file?->getClientOriginalExtension())) {
                'jpg', 'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'webp' => 'image/webp',
                default => $mime,
            };
        }

        return new JsonResponse(['data' => $visual->search($bytes, $mime, $this->filters($request, $data))]);
    }

    public function click(Request $request): JsonResponse
    {
        $data = $request->validate([
            'offer_id' => ['required', 'string', 'regex:/^[a-f0-9]{24}$/'],
        ]);

        return new JsonResponse(['data' => $this->compare->click($request->user(), $data['offer_id'])]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{brand: string|null, size: string|null, min_minor: int|null, max_minor: int|null, in_stock: bool, sort: string, currency: string, country: string}
     */
    private function filters(Request $request, array $data): array
    {
        return [
            'brand' => $this->blank($data['brand'] ?? null),
            'size' => $this->blank($data['size'] ?? null),
            'min_minor' => isset($data['min_minor']) && $data['min_minor'] !== null ? (int) $data['min_minor'] : null,
            'max_minor' => isset($data['max_minor']) && $data['max_minor'] !== null ? (int) $data['max_minor'] : null,
            'in_stock' => array_key_exists('in_stock', $data) ? $request->boolean('in_stock') : true,
            'sort' => is_string($data['sort'] ?? null) ? $data['sort'] : 'total_asc',
            'currency' => $this->currency($data['currency'] ?? 'USD'),
            'country' => $this->country($request, $data['country'] ?? null),
        ];
    }

    private function currency(mixed $value): string
    {
        $currency = strtoupper((string) $value);
        if (! $this->fx->supports($currency)) {
            throw ValidationException::withMessages(['currency' => 'Choose a currency we have a rate for.']);
        }

        return $currency;
    }

    private function country(Request $request, mixed $value): string
    {
        return Region::country(is_string($value) ? $value : null)
            ?? Region::country($request->user()?->shop_country)
            ?? Region::detect($request);
    }

    private function blank(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}

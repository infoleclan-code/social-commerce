<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Commerce\ProductGraphService;
use App\Services\Commerce\SellerService;
use App\Services\Feed\TasteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class GrowthEngineController extends Controller
{
    public function __construct(
        private readonly TasteService $taste,
        private readonly SellerService $sellers,
    ) {}

    public function taste(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->taste->status($request->user())]);
    }

    public function saveTaste(Request $request): JsonResponse
    {
        $data = $request->validate([
            'styles' => ['required', 'array', 'min:1', 'max:6'],
            'styles.*' => ['string', 'max:40'],
        ]);

        return new JsonResponse(['data' => $this->taste->save($request->user(), $data['styles'])]);
    }

    public function tap(Request $request, string $post): JsonResponse
    {
        $this->taste->observe($request->user(), $post, 'tap');

        return new JsonResponse(['data' => ['tapped' => true]]);
    }

    public function saveProduct(Request $request, string $product): JsonResponse
    {
        $this->taste->saveProduct($request->user(), $product);

        return new JsonResponse(['data' => ['saved' => true]]);
    }

    public function seller(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->sellers->profile($request->user())]);
    }

    public function registerSeller(Request $request): JsonResponse
    {
        $data = $request->validate([
            'legal_name' => ['required', 'string', 'min:2', 'max:160'],
            'country' => ['required', 'string', 'size:2'],
            'registration_number' => ['required', 'string', 'min:2', 'max:40'],
        ]);

        return new JsonResponse(['data' => $this->sellers->register(
            $request->user(),
            $data['legal_name'],
            $data['country'],
            $data['registration_number'],
        )], 201);
    }

    public function refreshSeller(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->sellers->refresh($request->user())]);
    }

    public function ship(Request $request, string $order): JsonResponse
    {
        $data = $request->validate([
            'carrier' => ['required', 'string', 'min:2', 'max:40'],
            'tracking_number' => ['required', 'string', 'min:4', 'max:64'],
        ]);

        return new JsonResponse(['data' => $this->sellers->ship($request->user(), $order, $data['carrier'], $data['tracking_number'])]);
    }

    public function syncCatalog(Request $request): JsonResponse
    {
        $data = $request->validate([
            'products' => ['required', 'array', 'min:1', 'max:100'],
            'products.*.sku' => ['required', 'string', 'max:40'],
            'products.*.title' => ['required', 'string', 'max:160'],
            'products.*.brand' => ['required', 'string', 'max:80'],
            'products.*.image_url' => ['required', 'string', 'max:500', 'url:https', 'regex:/^https:\/\/[^\s@]+$/'],
            'products.*.gtin' => ['sometimes', 'string', 'regex:/^(\d{8}|\d{12}|\d{13}|\d{14})$/'],
            'products.*.currency' => ['sometimes', 'string', 'regex:/^[A-Z]{3}$/'],
            'products.*.price_minor' => ['required', 'integer', 'min:1', 'max:100000000'],
            'products.*.stock' => ['required', 'integer', 'min:0', 'max:1000000'],
            'products.*.ships_from' => ['sometimes', 'string', 'size:2'],
            'products.*.shipping_minor' => ['sometimes', 'integer', 'min:0'],
            'products.*.vat_bps' => ['sometimes', 'integer', 'min:0', 'max:3000'],
            'products.*.duty_bps' => ['sometimes', 'integer', 'min:0', 'max:3000'],
            'products.*.handling_days' => ['sometimes', 'integer', 'min:0', 'max:21'],
        ]);

        return new JsonResponse(['data' => $this->sellers->sync($request->user(), $data['products'])]);
    }

    public function offers(Request $request, string $product): JsonResponse
    {
        $data = $request->validate(['country' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Za-z]{2}$/']]);

        return new JsonResponse(['data' => app(ProductGraphService::class)->offers(
            $product,
            $data['country'] ?? $request->user()?->country_code,
        )]);
    }

    public function related(string $product): JsonResponse
    {
        return new JsonResponse(['data' => app(ProductGraphService::class)->related($product)]);
    }
}

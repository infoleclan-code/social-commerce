<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Commerce\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CartController extends Controller
{
    public function __construct(private readonly CartService $cart) {}

    public function index(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->cart->items($request->user())]);
    }

    public function put(Request $request, string $product): JsonResponse
    {
        $data = $request->validate(['quantity' => ['required', 'integer', 'min:1', 'max:3']]);
        $this->cart->put($request->user(), $product, (int) $data['quantity']);

        return new JsonResponse(['data' => $this->cart->items($request->user())]);
    }

    public function destroy(Request $request, string $product): JsonResponse
    {
        $this->cart->remove($request->user(), $product);

        return new JsonResponse(null, 204);
    }

    public function checkout(Request $request): JsonResponse
    {
        $data = $request->validate(['address_id' => ['sometimes', 'nullable', 'uuid']]);
        $result = $this->cart->checkout($request->user(), $data['address_id'] ?? null, $this->idempotencyKey($request));

        return new JsonResponse(['data' => $result], 201);
    }

    public function addresses(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->cart->addresses($request->user())]);
    }

    public function storeAddress(Request $request): JsonResponse
    {
        $data = $request->validate([
            'label' => ['sometimes', 'nullable', 'string', 'min:1', 'max:40'],
            'line' => ['required', 'string', 'min:4', 'max:120'],
            'locality' => ['required', 'string', 'min:2', 'max:80'],
            'postal_code' => ['required', 'string', 'min:2', 'max:16'],
            'country' => ['required', 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        return new JsonResponse(['data' => $this->cart->saveAddress($request->user(), $data)], 201);
    }

    public function destroyAddress(Request $request, string $address): JsonResponse
    {
        $this->cart->deleteAddress($request->user(), $address);

        return new JsonResponse(null, 204);
    }
}

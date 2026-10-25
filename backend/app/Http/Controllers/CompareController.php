<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Compare\CompareService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Product search across external stores. The response is a plain array, never a model. */
final class CompareController extends Controller
{
    public function __construct(private readonly CompareService $compare) {}

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
            'currency' => ['sometimes', 'in:USD,EUR,GBP'],
        ]);

        return new JsonResponse(['data' => $this->compare->search($data['q'], [
            'brand' => $data['brand'] ?? null,
            'size' => $data['size'] ?? null,
            'min_minor' => isset($data['min_minor']) ? (int) $data['min_minor'] : null,
            'max_minor' => isset($data['max_minor']) ? (int) $data['max_minor'] : null,
            'in_stock' => array_key_exists('in_stock', $data) ? $request->boolean('in_stock') : true,
            'sort' => $data['sort'] ?? 'total_asc',
            'currency' => $data['currency'] ?? 'USD',
        ])]);
    }

    public function click(Request $request): JsonResponse
    {
        $data = $request->validate([
            'offer_id' => ['required', 'string', 'regex:/^[a-f0-9]{24}$/'],
        ]);

        return new JsonResponse(['data' => $this->compare->click($request->user(), $data['offer_id'])]);
    }
}

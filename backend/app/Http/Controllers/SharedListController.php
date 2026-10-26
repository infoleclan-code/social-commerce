<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Compare\FxRates;
use App\Services\Compare\SharedLists;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Shared lists, votes, and a pledge tally. The app does not collect the money. */
final class SharedListController extends Controller
{
    public function __construct(
        private readonly SharedLists $lists,
        private readonly FxRates $fx,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->lists->mine($request->user())]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'min:2', 'max:80'],
            'currency' => ['sometimes', 'regex:/^[A-Za-z]{3}$/'],
        ]);
        $currency = strtoupper($data['currency'] ?? ($request->user()->shop_currency ?: 'USD'));
        if (! $this->fx->supports($currency)) {
            throw ValidationException::withMessages(['currency' => 'Choose a currency we have a rate for.']);
        }

        return new JsonResponse(['data' => $this->lists->create($request->user(), trim($data['title']), $currency)], 201);
    }

    public function show(Request $request, string $list): JsonResponse
    {
        return new JsonResponse(['data' => $this->lists->show($request->user(), $list)]);
    }

    public function join(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'regex:/^[a-f0-9]{16}$/'],
        ]);

        return new JsonResponse(['data' => $this->lists->join($request->user(), $data['token'])]);
    }

    public function addItem(Request $request, string $list): JsonResponse
    {
        $data = $request->validate([
            'query' => ['required', 'string', 'min:2', 'max:120'],
            'brand' => ['sometimes', 'nullable', 'string', 'max:80'],
            'size' => ['sometimes', 'nullable', 'string', 'max:40'],
        ]);

        return new JsonResponse(['data' => $this->lists->addItem(
            $request->user(),
            $list,
            trim($data['query']),
            $this->blank($data['brand'] ?? null),
            $this->blank($data['size'] ?? null),
        )], 201);
    }

    public function vote(Request $request, string $list): JsonResponse
    {
        $data = $request->validate([
            'item_id' => ['required', 'uuid'],
            'vote' => ['required', 'in:up,down'],
        ]);

        return new JsonResponse(['data' => $this->lists->vote($request->user(), $list, $data['item_id'], $data['vote'])]);
    }

    public function pledge(Request $request, string $list): JsonResponse
    {
        $data = $request->validate([
            'amount_minor' => ['required', 'integer', 'min:1', 'max:100000000'],
            'note' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        return new JsonResponse(['data' => $this->lists->pledge(
            $request->user(),
            $list,
            (int) $data['amount_minor'],
            $this->blank($data['note'] ?? null),
        )], 201);
    }

    private function blank(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}

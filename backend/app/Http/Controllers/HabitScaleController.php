<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Commerce\HabitScaleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class HabitScaleController extends Controller
{
    public function __construct(private readonly HabitScaleService $habit) {}

    public function createGift(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'uuid'],
            'post_id' => ['nullable', 'uuid'],
        ]);

        $gift = $this->habit->createGift($request->user(), $data['product_id'], $data['post_id'] ?? null, $this->idempotencyKey($request));

        return new JsonResponse(['data' => $gift], $gift['replayed'] ? 200 : 201);
    }

    public function showGift(Request $request, string $token): JsonResponse
    {
        return new JsonResponse(['data' => $this->habit->showGift($request->user(), $token)]);
    }

    public function claimGift(Request $request, string $token): JsonResponse
    {
        $data = $request->validate([
            'line' => ['required', 'string', 'min:4', 'max:120'],
            'locality' => ['required', 'string', 'min:2', 'max:80'],
            'postal_code' => ['required', 'string', 'min:2', 'max:16'],
            'country' => ['required', 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/'],
        ]);

        return new JsonResponse(['data' => $this->habit->claimGift($request->user(), $token, $data)]);
    }

    public function ritual(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->habit->ritual($request->user())]);
    }

    public function checkIn(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->habit->checkIn($request->user())]);
    }

    public function mission(Request $request, string $kind): JsonResponse
    {
        return new JsonResponse(['data' => $this->habit->completeMission($request->user(), $kind)]);
    }

    public function spin(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->habit->spin($request->user())]);
    }

    public function renderTryOn(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'uuid'],
            'image_base64' => ['nullable', 'string', 'max:11184812'],
        ]);
        $bytes = null;
        if (! empty($data['image_base64'])) {
            $encoded = (string) $data['image_base64'];
            if (str_contains($encoded, ',')) {
                $encoded = substr($encoded, (int) strpos($encoded, ',') + 1);
            }
            $decoded = base64_decode($encoded, true);
            $bytes = is_string($decoded) ? $decoded : '';
        }

        return new JsonResponse(['data' => $this->habit->renderTryOn($request->user(), $data['product_id'], $bytes)], 201);
    }

    public function openPoll(Request $request, string $render): JsonResponse
    {
        return new JsonResponse(['data' => $this->habit->openPoll($request->user(), $render)], 201);
    }

    public function showPoll(Request $request, string $token): JsonResponse
    {
        return new JsonResponse(['data' => $this->habit->poll($token, $request->user())]);
    }

    public function vote(Request $request, string $token): JsonResponse
    {
        $data = $request->validate(['choice' => ['required', 'in:wear,skip']]);

        return new JsonResponse(['data' => $this->habit->vote($request->user(), $token, $data['choice'])]);
    }

    public function closet(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->habit->closet($request->user())]);
    }

    public function listCloset(Request $request, string $item): JsonResponse
    {
        return new JsonResponse(['data' => $this->habit->listCloset($request->user(), $item)]);
    }

    public function auction(): JsonResponse
    {
        return new JsonResponse(['data' => $this->habit->liveAuction()]);
    }

    public function bid(Request $request, string $auction): JsonResponse
    {
        $data = $request->validate(['amount_minor' => ['required', 'integer', 'min:1', 'max:'.(int) config('commerce.max_order_minor')]]);

        return new JsonResponse(['data' => $this->habit->bid($request->user(), $auction, (int) $data['amount_minor'])]);
    }

    public function blindBox(): JsonResponse
    {
        return new JsonResponse(['data' => $this->habit->blindBox()]);
    }

    public function pullBox(Request $request, string $box): JsonResponse
    {
        $pull = $this->habit->pullBox($request->user(), $box, $this->idempotencyKey($request));

        return new JsonResponse(['data' => $pull], $pull['replayed'] ? 200 : 201);
    }
}

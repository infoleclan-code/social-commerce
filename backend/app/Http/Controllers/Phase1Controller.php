<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Services\Commerce\DropHourService;
use App\Services\Commerce\ScanAndTapService;
use App\Services\Commerce\ShareEarnService;
use App\Services\Commerce\SquadPriceService;
use App\Services\Payments\CardPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class Phase1Controller extends Controller
{
    public function __construct(
        private readonly SquadPriceService $squads,
        private readonly ShareEarnService $shares,
        private readonly DropHourService $drops,
        private readonly ScanAndTapService $scan,
        private readonly CardPaymentService $cards,
    ) {}

    public function authorizeSquad(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'uuid'],
            'post_id' => ['nullable', 'uuid'],
        ]);
        $key = $this->idempotencyKey($request);

        $result = $this->squads->authorize($request->user(), $data['product_id'], $data['post_id'] ?? null, $key);

        return new JsonResponse(['data' => $result], $result['replayed'] ? 200 : 201);
    }

    public function joinSquad(Request $request, string $squad): JsonResponse
    {
        $result = $this->squads->join($request->user(), $squad, $this->idempotencyKey($request));

        return new JsonResponse(['data' => $result], $result['replayed'] ? 200 : 201);
    }

    public function settleSquad(Request $request, string $squad): JsonResponse
    {
        return new JsonResponse(['data' => $this->squads->settle($squad, $request->user())]);
    }

    public function openReferral(Request $request, string $token): JsonResponse
    {
        $payload = $this->shares->open($token, $request->user());
        if ($payload === null) {
            abort(404);
        }

        return new JsonResponse(['data' => $payload]);
    }

    public function ledger(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->shares->ledger($request->user())]);
    }

    public function dropHour(): JsonResponse
    {
        return new JsonResponse(['data' => $this->drops->current()]);
    }

    public function reserveDrop(Request $request, string $drop): JsonResponse
    {
        return new JsonResponse(['data' => $this->drops->reserve($request->user(), $drop)], 201);
    }

    public function visualSearch(Request $request): JsonResponse
    {
        $request->validate([
            'category' => ['nullable', 'string', 'max:80'],
            'post_id' => ['nullable', 'uuid'],
            'image' => ['nullable', 'required_without:image_base64', 'file', 'max:8192', 'mimetypes:image/jpeg,image/png,image/webp'],
            'image_base64' => ['nullable', 'required_without:image', 'string', 'max:11184816'],
            'image_sha256' => ['nullable', 'string', 'regex:/^[A-Fa-f0-9]{64}$/'],
        ]);

        return new JsonResponse(['data' => $this->scan->search(
            $request->user(),
            $this->imageBytes($request),
            $request->input('category'),
            $request->input('post_id'),
            $request->input('image_sha256'),
        )]);
    }

    public function oneTap(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'uuid'],
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:3'],
            'expected_total_minor' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ]);
        $result = $this->scan->oneTap(
            $request->user(),
            $data['product_id'],
            (int) ($data['quantity'] ?? 1),
            $this->idempotencyKey($request),
            isset($data['expected_total_minor']) ? (int) $data['expected_total_minor'] : null,
        );

        return new JsonResponse(['data' => $result], $result['replayed'] ? 200 : 201);
    }

    public function orders(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->scan->listOrders($request->user())]);
    }

    public function showOrder(Request $request, string $order): JsonResponse
    {
        return new JsonResponse(['data' => $this->scan->showOrder($request->user(), $order)]);
    }

    public function cancelOrder(Request $request, string $order): JsonResponse
    {
        return new JsonResponse(['data' => $this->scan->cancel($request->user(), $order)]);
    }

    public function syncOrder(Request $request, string $order): JsonResponse
    {
        return new JsonResponse(['data' => $this->scan->syncOrder($request->user(), $order)]);
    }

    public function beginSetup(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->cards->beginSetup($request->user(), $this->idempotencyKey($request))]);
    }

    public function confirmSetup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'setup_intent_id' => ['required_without:payment_method_id', 'nullable', 'string', 'regex:/^seti_[A-Za-z0-9_]{1,100}$/'],
            'payment_method_id' => ['required_without:setup_intent_id', 'nullable', 'string', 'regex:/^pm_[A-Za-z0-9_]{1,100}$/'],
        ]);

        return new JsonResponse(['data' => $this->cards->confirmSetup(
            $request->user(),
            $data['setup_intent_id'] ?? null,
            $data['payment_method_id'] ?? null,
        )]);
    }

    public function paymentMethod(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->scan->paymentMethod($request->user())]);
    }

    /** Raw bytes of the upload; base64 is length-checked and strictly decoded (ImageGuard). */
    private function imageBytes(Request $request): string
    {
        $file = $request->file('image');
        if ($file !== null) {
            $bytes = file_get_contents($file->getRealPath());

            return is_string($bytes) ? $bytes : '';
        }
        $encoded = (string) $request->input('image_base64', '');
        if (preg_match('#^data:image/(jpeg|png|webp);base64,#', $encoded, $prefix) === 1) {
            $encoded = substr($encoded, strlen($prefix[0]));
        }
        $bytes = base64_decode($encoded, true);
        if (! is_string($bytes)) {
            throw ApiException::imageInvalid('The image is not valid base64.');
        }

        return $bytes;
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Services\Commerce\AutoTagService;
use App\Services\Commerce\PriceGuard;
use App\Services\Commerce\SyntheticMediaService;
use App\Services\Commerce\VerifiedReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DifferentiatorController extends Controller
{
    public function __construct(
        private readonly AutoTagService $tags,
        private readonly SyntheticMediaService $synthetic,
        private readonly PriceGuard $prices,
        private readonly VerifiedReviewService $reviews,
    ) {}

    public function autoTag(Request $request, string $post): JsonResponse
    {
        $owned = $request->user()->feedPosts()->whereKey($post)->exists();
        if (! $owned) {
            throw ApiException::postNotFound();
        }
        $request->validate(['frame' => ['required', 'file', 'max:8192', 'mimetypes:image/jpeg,image/png,image/webp']]);
        $bytes = (string) file_get_contents($request->file('frame')->getRealPath());

        return new JsonResponse(['data' => $this->tags->match($post, $bytes)]);
    }

    public function synthetic(Request $request, string $post): JsonResponse
    {
        $data = $request->validate([
            'language' => ['required', 'string', 'size:2'],
            'kind' => ['required', 'in:dub,subtitle'],
        ]);

        return new JsonResponse(['data' => $this->synthetic->publish(
            $request->user(),
            $post,
            $data['language'],
            $data['kind'],
        )], 201);
    }

    public function priceProtection(Request $request, string $order): JsonResponse
    {
        return new JsonResponse(['data' => $this->prices->status($order, (string) $request->user()->id)]);
    }

    public function reviews(Request $request, string $product): JsonResponse
    {
        return new JsonResponse(['data' => $this->reviews->thread($request->user(), $product)]);
    }

    public function review(Request $request, string $product): JsonResponse
    {
        $data = $request->validate([
            'order_id' => ['required', 'uuid'],
            'body' => ['required', 'string', 'min:1', 'max:500'],
            'video' => ['nullable', 'file', 'max:51200'],
        ]);

        return new JsonResponse(['data' => $this->reviews->review(
            $request->user(),
            $product,
            $data['order_id'],
            $data['body'],
            $request->file('video'),
        )], 201);
    }

    public function ask(Request $request, string $product): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'min:1', 'max:500']]);

        return new JsonResponse(['data' => $this->reviews->ask($request->user(), $product, $data['body'])], 201);
    }

    public function answer(Request $request, string $question): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'min:1', 'max:500']]);

        return new JsonResponse(['data' => $this->reviews->answer($request->user(), $question, $data['body'])], 201);
    }
}

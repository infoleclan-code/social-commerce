<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Social\SocialGraphService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SocialController extends Controller
{
    public function __construct(private readonly SocialGraphService $social) {}

    public function request(Request $request, string $user): JsonResponse
    {
        return new JsonResponse(['data' => $this->social->request($request->user(), $user)], 201);
    }

    public function accept(Request $request, string $user): JsonResponse
    {
        return new JsonResponse(['data' => $this->social->accept($request->user(), $user)]);
    }

    public function decline(Request $request, string $user): JsonResponse
    {
        return new JsonResponse(['data' => $this->social->decline($request->user(), $user)]);
    }

    public function block(Request $request, string $user): JsonResponse
    {
        return new JsonResponse(['data' => $this->social->block($request->user(), $user)]);
    }

    public function unblock(Request $request, string $user): JsonResponse
    {
        $this->social->unblock($request->user(), $user);

        return new JsonResponse(null, 204);
    }
}

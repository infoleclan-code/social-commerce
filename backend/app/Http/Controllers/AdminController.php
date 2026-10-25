<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\SellerStatus;
use App\Services\Admin\AdminService;
use App\Services\Auth\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

final class AdminController extends Controller
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly AdminService $admin,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'string', Password::min(8)->max(200)],
            'otp' => ['required', 'string', 'max:12'],
        ]);

        return new JsonResponse(['data' => $this->auth->loginAdmin($data['email'], $data['password'], $data['otp'])]);
    }

    public function auctions(): JsonResponse
    {
        return new JsonResponse(['data' => $this->admin->auctions()]);
    }

    public function createAuction(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'uuid'],
            'start_minor' => ['required', 'integer', 'min:0'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date'],
        ]);

        return new JsonResponse(['data' => $this->admin->createAuction(
            $data['product_id'],
            (int) $data['start_minor'],
            $data['starts_at'],
            $data['ends_at'],
        )], 201);
    }

    public function scheduleAuction(Request $request, string $auction): JsonResponse
    {
        $data = $request->validate([
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date'],
        ]);

        return new JsonResponse(['data' => $this->admin->scheduleAuction($auction, $data['starts_at'], $data['ends_at'])]);
    }

    public function endAuction(string $auction): JsonResponse
    {
        return new JsonResponse(['data' => $this->admin->endAuction($auction)]);
    }

    public function products(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->admin->products($request->query('q'))]);
    }

    public function createProduct(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'min:1', 'max:160'],
            'brand' => ['required', 'string', 'min:1', 'max:80'],
            'image_url' => ['required', 'url', 'max:500', 'regex:/^https:\/\//'],
            'price_minor' => ['required', 'integer', 'min:0'],
            'stock' => ['required', 'integer', 'min:0'],
            'listing_status' => ['sometimes', 'in:active,review,blocked'],
        ]);

        return new JsonResponse(['data' => $this->admin->createProduct($data)], 201);
    }

    public function updateProduct(Request $request, string $product): JsonResponse
    {
        $data = $request->validate([
            'listing_status' => ['required', 'in:active,review,blocked'],
            'stock' => ['sometimes', 'integer', 'min:0'],
        ]);

        return new JsonResponse(['data' => $this->admin->updateProduct(
            $product,
            $data['listing_status'],
            isset($data['stock']) ? (int) $data['stock'] : null,
        )]);
    }

    public function sellers(): JsonResponse
    {
        return new JsonResponse(['data' => $this->admin->sellers()]);
    }

    public function verifySeller(string $user): JsonResponse
    {
        return new JsonResponse(['data' => $this->admin->setSellerStatus($user, SellerStatus::Verified)]);
    }

    public function rejectSeller(string $user): JsonResponse
    {
        return new JsonResponse(['data' => $this->admin->setSellerStatus($user, SellerStatus::Rejected)]);
    }

    public function posts(): JsonResponse
    {
        return new JsonResponse(['data' => $this->admin->posts()]);
    }

    public function hidePost(Request $request, string $post): JsonResponse
    {
        $this->admin->hidePost($request->user(), $post, true);

        return new JsonResponse(['data' => ['hidden' => true]]);
    }

    public function unhidePost(Request $request, string $post): JsonResponse
    {
        $this->admin->hidePost($request->user(), $post, false);

        return new JsonResponse(['data' => ['hidden' => false]]);
    }

    public function removePost(string $post): JsonResponse
    {
        $this->admin->removePost($post);

        return new JsonResponse(null, 204);
    }

    public function comments(): JsonResponse
    {
        return new JsonResponse(['data' => $this->admin->comments()]);
    }

    public function hideComment(Request $request, string $comment): JsonResponse
    {
        $this->admin->hideComment($request->user(), $comment, true);

        return new JsonResponse(['data' => ['hidden' => true]]);
    }

    public function unhideComment(Request $request, string $comment): JsonResponse
    {
        $this->admin->hideComment($request->user(), $comment, false);

        return new JsonResponse(['data' => ['hidden' => false]]);
    }

    public function removeComment(string $comment): JsonResponse
    {
        $this->admin->removeComment($comment);

        return new JsonResponse(null, 204);
    }

    public function users(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->admin->users($request->query('q'))]);
    }

    public function ban(Request $request, string $user): JsonResponse
    {
        $this->admin->setBan($request->user(), $user, true);

        return new JsonResponse(['data' => ['banned' => true]]);
    }

    public function unban(Request $request, string $user): JsonResponse
    {
        $this->admin->setBan($request->user(), $user, false);

        return new JsonResponse(['data' => ['banned' => false]]);
    }
}

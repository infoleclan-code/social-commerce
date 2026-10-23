<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Auth\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

final class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'string', Password::min(8)->max(200)],
            'username' => ['required', 'string', 'regex:/^[A-Za-z0-9_.]{3,30}$/', 'unique:users,username'],
            'display_name' => ['required', 'string', 'min:1', 'max:60'],
        ]);

        return new JsonResponse(['data' => $this->auth->register(
            $data['email'],
            $data['password'],
            $data['username'],
            $data['display_name'],
        )], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'string', 'max:200'],
        ]);

        return new JsonResponse(['data' => $this->auth->login($data['email'], $data['password'])]);
    }

    public function logout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_id' => ['sometimes', 'nullable', 'uuid'],
        ]);
        $this->auth->logout($request->user(), $data['device_id'] ?? null);

        return new JsonResponse(['data' => ['signed_out' => true]]);
    }
}

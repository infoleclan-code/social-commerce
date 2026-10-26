<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\Auth\AuthService;
use App\Support\AuthCookie;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

final class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'string', Password::min(8)->max(200)],
            'username' => ['required', 'string', 'regex:/^[A-Za-z0-9_.]{3,30}$/'],
            'display_name' => ['required', 'string', 'min:1', 'max:60'],
        ]);
        $emailTaken = User::query()->whereRaw('lower(email) = lower(?)', [$data['email']])->exists();
        $usernameTaken = User::query()->whereRaw('lower(username) = lower(?)', [$data['username']])->exists();
        if ($emailTaken || $usernameTaken) {
            throw ApiException::accountUnavailable();
        }

        try {
            $session = $this->auth->register(
                $data['email'],
                $data['password'],
                $data['username'],
                $data['display_name'],
            );
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? '') === '23505') {
                throw ApiException::accountUnavailable();
            }
            throw $exception;
        }

        return (new JsonResponse(['data' => $session], 201))->withCookie(AuthCookie::make($session['token']));
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'string', 'max:200'],
        ]);

        $session = $this->auth->login($data['email'], $data['password']);

        return (new JsonResponse(['data' => $session]))->withCookie(AuthCookie::make($session['token']));
    }

    public function logout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_id' => ['sometimes', 'nullable', 'uuid'],
        ]);
        $this->auth->logout($request->user(), $data['device_id'] ?? null);

        return (new JsonResponse(['data' => ['signed_out' => true]]))->withCookie(AuthCookie::forget());
    }
}

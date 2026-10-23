<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Email and password sessions for the mobile app. Tokens expire (config sanctum.expiration,
 * 30 days unless SANCTUM_EXPIRATION says otherwise). Logout deletes the current token.
 * A development bearer token issued outside this flow still works until it expires.
 */
final class AuthService
{
    /** @return array{token: string, user: array<string, mixed>} */
    public function register(string $email, string $password, string $username, string $displayName): array
    {
        $user = User::query()->create([
            'email' => $email,
            'password' => $password,
            'username' => $username,
            'display_name' => $displayName,
            'social_feed_enabled' => false,
        ]);

        return $this->issue($user);
    }

    /** @return array{token: string, user: array<string, mixed>} */
    public function login(string $email, string $password): array
    {
        $user = User::query()->whereRaw('email = ?', [$email])->first();
        if ($user === null || ! is_string($user->password) || ! Hash::check($password, $user->password)) {
            throw ApiException::authFailed();
        }
        if ($user->anonymized_at !== null || $user->deleted_at !== null) {
            throw ApiException::authFailed();
        }

        return $this->issue($user);
    }

    public function logout(User $user, ?string $deviceId = null): void
    {
        $token = $user->currentAccessToken();
        if ($token !== null && method_exists($token, 'delete')) {
            $token->delete();
        }
        if ($deviceId !== null) {
            DB::table('user_devices')
                ->where('id', $deviceId)
                ->where('user_id', $user->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);
        }
    }

    /** @return array{token: string, user: array<string, mixed>} */
    private function issue(User $user): array
    {
        $minutes = config('sanctum.expiration');
        $expires = is_numeric($minutes) ? now()->addMinutes((int) $minutes) : null;
        $token = $user->createToken('mobile', ['*'], $expires);

        return [
            'token' => $token->plainTextToken,
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'display_name' => $user->display_name,
            ],
        ];
    }
}

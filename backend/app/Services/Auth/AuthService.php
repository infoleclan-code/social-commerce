<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\RiskStatus;
use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Throwable;

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
        if ($user->risk_status === RiskStatus::Banned) {
            throw ApiException::accountBanned();
        }
        if ($user->anonymized_at !== null || $user->deleted_at !== null) {
            throw ApiException::authFailed();
        }

        return $this->issue($user);
    }

    /**
     * A separate admin session. The password is checked first. The one-time code is a TOTP from the
     * admin's secret, or the local demo code when ADMIN_DEMO_OTP is set outside production.
     *
     * @return array{token: string, user: array<string, mixed>}
     */
    public function loginAdmin(string $email, string $password, string $otp): array
    {
        $user = User::query()->whereRaw('email = ?', [$email])->first();
        if ($user === null || $user->role !== UserRole::Admin || ! is_string($user->password) || ! Hash::check($password, $user->password)) {
            throw ApiException::authFailed();
        }
        if ($user->anonymized_at !== null || $user->deleted_at !== null || $user->risk_status === RiskStatus::Banned) {
            throw ApiException::accountBanned();
        }
        if (! $this->otpOk($user, trim($otp))) {
            throw ApiException::totpInvalid();
        }

        return $this->issue($user, 'admin', ['admin']);
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

    /**
     * @param  list<string>  $abilities
     * @return array{token: string, user: array<string, mixed>}
     */
    private function issue(User $user, string $name = 'mobile', array $abilities = ['mobile']): array
    {
        $minutes = config('sanctum.expiration');
        $expires = is_numeric($minutes) ? now()->addMinutes((int) $minutes) : null;
        $token = $user->createToken($name, $abilities, $expires);

        return [
            'token' => $token->plainTextToken,
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'display_name' => $user->display_name,
                'role' => $user->role instanceof UserRole ? $user->role->value : UserRole::Member->value,
            ],
        ];
    }

    private function otpOk(User $user, string $otp): bool
    {
        $stored = $user->totp_secret;
        if (is_string($stored) && $stored !== '') {
            try {
                $secret = Crypt::decryptString($stored);
            } catch (Throwable) {
                $secret = '';
            }
            if ($secret !== '' && Totp::verify($secret, $otp)) {
                return true;
            }
        }
        $demo = (string) config('admin.demo_otp');

        return $demo !== '' && ! app()->environment('production') && hash_equals($demo, $otp);
    }
}

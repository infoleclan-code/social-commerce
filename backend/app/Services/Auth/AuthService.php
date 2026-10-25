<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\RiskStatus;
use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Mail\AccountWelcome;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
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

        try {
            Mail::to($user->email)->send(new AccountWelcome((string) $user->display_name));
        } catch (Throwable) {
            // Opening the account does not depend on the welcome note.
        }

        return $this->issue($user);
    }

    /** @return array{token: string, user: array<string, mixed>} */
    public function login(string $email, string $password): array
    {
        $user = User::query()->whereRaw('email = ?', [$email])->first();
        if (! $this->passwordMatches($user, $password)) {
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

    public function passwordMatches(?User $user, string $password): bool
    {
        $hash = is_string($user?->password) && $user->password !== '' ? $user->password : $this->dummyHash();
        $matches = Hash::check($password, $hash);

        return $user !== null && $matches;
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
        $passwordOk = $this->passwordMatches($user, $password);
        $code = trim($otp);
        $step = Totp::matchedStep($this->totpSecret($user), $code);
        $demoOk = $this->demoOtpOk($user, $code);
        $replay = $user !== null && $step !== null && $user->totp_last_step !== null && $step <= (int) $user->totp_last_step;
        $eligible = $user !== null
            && $passwordOk
            && $user->role === UserRole::Admin
            && $user->anonymized_at === null
            && $user->deleted_at === null
            && $user->risk_status !== RiskStatus::Banned;
        if (! $eligible || $replay || ($step === null && ! $demoOk)) {
            throw ApiException::authFailed();
        }
        if ($step !== null) {
            $user->forceFill(['totp_last_step' => $step])->save();
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

    /** A fixed secret so a missing admin still pays for a TOTP check. */
    private const DUMMY_TOTP_SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    private function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= Hash::make('not-a-user-password');
    }

    private function totpSecret(?User $user): string
    {
        $stored = $user?->totp_secret;
        if (is_string($stored) && $stored !== '') {
            try {
                $secret = Crypt::decryptString($stored);
                if ($secret !== '') {
                    return $secret;
                }
            } catch (Throwable) {
                // Keep going with the dummy secret.
            }
        }

        return self::DUMMY_TOTP_SECRET;
    }

    private function demoOtpOk(?User $user, string $otp): bool
    {
        $demo = (string) config('admin.demo_otp');
        if ($user === null || $demo === '' || app()->environment('production') || ! hash_equals($demo, $otp)) {
            return false;
        }

        return Cache::add('admin-demo-otp:'.$user->id.':'.intdiv(time(), 30), 1, 90);
    }
}

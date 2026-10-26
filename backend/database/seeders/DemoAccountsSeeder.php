<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Enums\Visibility;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Throwable;

/**
 * Demo buyer, admin, and the account that owns the sample reels. Runs only when APP_ENV=local.
 * The admin password and TOTP secret are generated when the admin is created, or when the
 * stored secret is still the one that used to be committed. They are printed once and are
 * not written back into the repository.
 */
class DemoAccountsSeeder extends Seeder
{
    /** SHA-256 of the TOTP secret that used to live in this file. The secret itself is not stored. */
    private const LEAKED_TOTP_SHA256 = 'ad60f5c7cadacad330ed4aa4d41086952f92a80215184f3cafdcf3dc6eb9c7ac';

    public function run(): void
    {
        if (! app()->environment('local')) {
            return;
        }

        $buyer = $this->account('buyer@example.com', 'demo_buyer', 'Demo Buyer', false);
        $admin = $this->adminAccount();
        $reels = $this->account('reels@example.com', 'demo_reels', 'Demo Reels', true);
        foreach ([$buyer, $admin, $reels] as $user) {
            if ($user->profile_visibility !== Visibility::Public) {
                $user->forceFill(['profile_visibility' => Visibility::Public])->save();
            }
        }
    }

    private function adminAccount(): User
    {
        $existing = User::query()->whereRaw('email = ?', ['admin@example.com'])->first();
        if ($existing !== null && ! $this->leaked($existing)) {
            $existing->forceFill([
                'role' => UserRole::Admin,
                'username' => 'demo_admin',
            ])->save();

            return $existing;
        }

        $password = Str::password(20, symbols: false);
        $secret = Totp::secret();
        $user = $existing ?? User::query()->create([
            'email' => 'admin@example.com',
            'password' => $password,
            'username' => 'demo_admin',
            'display_name' => 'Demo Admin',
            'social_feed_enabled' => true,
        ]);
        $user->forceFill([
            'role' => UserRole::Admin,
            'password' => $password,
            'totp_secret' => Crypt::encryptString($secret),
            'totp_last_step' => null,
            'social_feed_enabled' => true,
        ])->save();

        $this->command?->warn('Admin bootstrap (shown once; not stored in git):');
        $this->command?->warn('email=admin@example.com');
        $this->command?->warn('password='.$password);
        $this->command?->warn('totp_secret='.$secret);

        return $user;
    }

    private function leaked(User $user): bool
    {
        if (! is_string($user->totp_secret) || $user->totp_secret === '') {
            return true;
        }
        try {
            $secret = Crypt::decryptString($user->totp_secret);
        } catch (Throwable) {
            return true;
        }

        return hash_equals(self::LEAKED_TOTP_SHA256, hash('sha256', $secret));
    }

    private function account(string $email, string $username, string $name, bool $social): User
    {
        $existing = User::query()->whereRaw('email = ?', [$email])->first();
        if ($existing !== null) {
            return $existing;
        }

        return User::query()->create([
            'email' => $email,
            'password' => 'correct-horse',
            'username' => $username,
            'display_name' => $name,
            'social_feed_enabled' => $social,
        ]);
    }
}

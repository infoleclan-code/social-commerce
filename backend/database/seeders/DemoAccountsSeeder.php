<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;

/** Demo buyer, admin, and the account that owns the sample reels. Skipped in production. */
class DemoAccountsSeeder extends Seeder
{
    /** Base32 TOTP secret for the seeded admin. Any authenticator app can use it. */
    public const ADMIN_TOTP_SECRET = 'KZXW6YTBONSWG33OMVZXG33O';

    public function run(): void
    {
        if (app()->environment('production')) {
            return;
        }

        $this->account('buyer@example.com', 'demo_buyer', 'Demo Buyer', false);
        $admin = $this->account('admin@example.com', 'demo_admin', 'Demo Admin', true);
        $admin->forceFill([
            'role' => UserRole::Admin,
            'totp_secret' => Crypt::encryptString(self::ADMIN_TOTP_SECRET),
        ])->save();
        $this->account('reels@example.com', 'demo_reels', 'Demo Reels', true);

        $this->command?->info('Demo accounts are ready. Admin TOTP secret: '.self::ADMIN_TOTP_SECRET);
        $this->command?->info('Current admin code: '.Totp::code(self::ADMIN_TOTP_SECRET, intdiv(time(), 30)));
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

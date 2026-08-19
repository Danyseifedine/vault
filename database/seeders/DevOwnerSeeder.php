<?php

namespace Database\Seeders;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * A ready-to-use local account: claimed, verified, profile filled in - but
 * deliberately WITHOUT a second factor.
 *
 * Seeding a confirmed secret would drop you straight onto the code challenge
 * with nothing to generate a code from, and would hide the setup screen that
 * every real person meets. Signing in lands on it instead: scan the QR, and the
 * account is yours. This seeder refuses to run outside local development.
 */
class DevOwnerSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->tell('DevOwnerSeeder skipped: never seed a known password into production.');

            return;
        }

        // Read at runtime, not as a const: a class constant may only hold a
        // compile-time value, and config() is a function call.
        $email = (string) config('auth.owner.email');
        $password = (string) config('auth.owner.password');

        if ($email === '' || $password === '') {
            $this->tell('DevOwnerSeeder skipped: set OWNER_EMAIL and OWNER_PASSWORD in your .env.');

            return;
        }

        $user = User::withoutEvents(fn () => User::updateOrCreate(
            ['email' => $email],
            [
                'name' => 'Owner',
                'password' => $password, // hashed by the model cast
                'status' => UserStatus::Active,
                // The local account is the one that starts organizations.
                'can_create_organizations' => true,
                'email_verified_at' => now(),
                'job_title' => 'Owner',
                'stack' => ['Laravel', 'React', 'TypeScript'],
                'profile_completed_at' => now(),
            ],
        ));

        // Cleared rather than set: the point is to land on the setup screen.
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        $this->announce($user, $password);
    }

    private function announce(User $user, string $password): void
    {
        $this->tell('Owner ready - '.$user->email.' / '.$password);
        $this->tell('  Signing in lands on two-factor setup: scan the QR with your authenticator,');
        $this->tell('  type the six digits, and you are in. There are no recovery codes, so keep');
        $this->tell('  that app - `php artisan vault:reset-two-factor '.$user->email.'` is the way back.');
    }

    /** Reached through `db:seed` / `migrate --seed`, so there is always a console. */
    private function tell(string $message): void
    {
        $this->command->line($message);
    }
}

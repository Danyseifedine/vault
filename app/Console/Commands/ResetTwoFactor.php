<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Illuminate\Console\Command;

/**
 * The way back in when an authenticator app is gone.
 *
 * The Vault accepts no recovery codes, so there is no self-service route - by
 * design, since a code in a notes app is a second password. That leaves this:
 * clearing someone's second factor is effectively taking over their account,
 * so it costs shell access on the server rather than a button in a settings
 * page, and it is always written to the audit log.
 *
 * The account itself is untouched. The onboarding gate sees a missing
 * confirmation and walks them through setup again on their next sign-in.
 */
class ResetTwoFactor extends Command
{
    protected $signature = 'vault:reset-two-factor
        {email : The account that lost its authenticator}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Clear an account\'s two-factor secret so it can be set up again';

    public function handle(AuditRecorder $audit): int
    {
        $email = (string) $this->argument('email');
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("No account exists for {$email}.");

            return self::FAILURE;
        }

        if ($user->two_factor_secret === null && $user->two_factor_confirmed_at === null) {
            $this->line("{$email} has no second factor set up - nothing to clear.");

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Clear the second factor for {$user->email}?")) {
            $this->line('Cancelled.');

            return self::FAILURE;
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        $audit->record(
            'two-factor.reset',
            subject: $user,
            properties: ['email' => $user->email, 'via' => 'console'],
            causer: $user,
        );

        $this->info("Two-factor authentication cleared for {$user->email}.");
        $this->line('They will be asked to set it up again on their next sign-in, and cannot reach anything until they do.');

        return self::SUCCESS;
    }
}

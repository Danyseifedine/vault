<?php

namespace App\Console\Commands;

use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Bootstraps the very first account.
 *
 * Registration is closed and accounts only come from invitations - which
 * leaves a chicken-and-egg problem for the first person. This command is the
 * only way to create an account without an inviter, so it lives on the
 * command line where physical access to the server is already required.
 *
 * It grants nothing: this person holds no permissions until they create an
 * organization, which seeds them the full set for that organization alone.
 */
class CreateFirstUser extends Command
{
    protected $signature = 'vault:create-first-user
        {--name= : Their full name}
        {--email= : Their email address}
        {--password= : Their password}';

    protected $description = 'Create the first account (run once at install)';

    public function handle(AuditRecorder $audit): int
    {
        $attributes = [
            'name' => $this->option('name') ?: $this->ask('Full name'),
            'email' => $this->option('email') ?: $this->ask('Email address'),
            'password' => $this->option('password') ?: $this->secret('Password'),
        ];

        $validator = Validator::make($attributes, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::default()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $attributes['name'],
            'email' => $attributes['email'],
            'password' => $attributes['password'],
        ]);

        $user->forceFill([
            'status' => UserStatus::Active,
            // Somebody has to be able to start the first organization, and
            // there is nobody to grant it to them.
            'can_create_organizations' => true,
            'email_verified_at' => now(),
            'profile_completed_at' => now(),
        ])->save();

        $audit->record('account.bootstrapped', subject: $user, properties: ['via' => 'console'], causer: $user);

        $this->info("Account created for {$user->email}.");
        $this->line('Two-factor authentication is still required - set it up on first sign-in.');

        return self::SUCCESS;
    }
}

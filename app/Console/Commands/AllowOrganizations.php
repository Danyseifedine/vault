<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Illuminate\Console\Command;

/**
 * Hands out (or takes back) the capability to start organizations.
 *
 * It lives on the command line because no organization has authority over it:
 * a grant belongs to an organization, and starting a new one happens outside
 * every organization there is. Server access is the authority instead, exactly
 * as it is for creating the first account.
 */
class AllowOrganizations extends Command
{
    protected $signature = 'vault:allow-organizations
        {email : The account to allow}
        {--revoke : Take the capability away instead}';

    protected $description = 'Allow an account to start organizations (or revoke it)';

    public function handle(AuditRecorder $audit): int
    {
        $email = $this->argument('email');
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("No account here uses {$email}.");

            return self::FAILURE;
        }

        $allow = ! $this->option('revoke');

        $user->forceFill(['can_create_organizations' => $allow])->save();

        $audit->record(
            $allow ? 'account.organizations-allowed' : 'account.organizations-revoked',
            subject: $user,
            properties: ['via' => 'console'],
            causer: $user,
        );

        $this->info($allow
            ? "{$user->email} can now start organizations."
            : "{$user->email} can no longer start organizations.");

        return self::SUCCESS;
    }
}

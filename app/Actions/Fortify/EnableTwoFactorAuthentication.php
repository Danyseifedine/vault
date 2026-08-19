<?php

namespace App\Actions\Fortify;

use App\Services\Audit\AuditRecorder;
use App\Services\Audit\AuditScope;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication as FortifyEnableTwoFactorAuthentication;

/**
 * Fortify mints eight recovery codes whenever two-factor authentication is
 * turned on. Nothing in The Vault can redeem them (see TwoFactorChallengeRequest),
 * and an unusable secret sitting in the database is still a secret worth
 * stealing - so we throw them away as they are created.
 */
class EnableTwoFactorAuthentication extends FortifyEnableTwoFactorAuthentication
{
    public function __invoke($user, $force = false): void
    {
        parent::__invoke($user, $force);

        if ($user->two_factor_recovery_codes !== null) {
            $user->forceFill(['two_factor_recovery_codes' => null])->save();
        }

        app(AuditRecorder::class)->record(
            'account.two-factor-provisioned',
            scope: AuditScope::none(),
            causer: $user,
        );
    }
}

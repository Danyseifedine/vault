<?php

namespace App\Actions\Onboarding;

use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Audit\AuditRecorder;

/**
 * Claims a reserved seat: the invited account gets its first password and
 * becomes active. The grants written at invite time simply come alive -
 * nothing is recalculated here.
 */
class SetInitialPassword
{
    public function __construct(private AuditRecorder $audit) {}

    public function __invoke(User $user, string $password): User
    {
        $user->forceFill([
            'password' => $password,
            'status' => UserStatus::Active,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        $this->audit->record('onboarding.password-set', subject: $user, causer: $user);

        return $user;
    }
}

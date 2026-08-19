<?php

namespace App\Enums;

use App\Models\User;

enum OnboardingStep: string
{
    case Password = 'password';
    case TwoFactor = 'two-factor';
    case Profile = 'profile';
    case Done = 'done';

    /**
     * The first step this user still owes us. The order is deliberate:
     * secure the account before decorating it.
     */
    public static function for(User $user): self
    {
        return match (true) {
            $user->isInvited() => self::Password,
            ! $user->hasConfirmedTwoFactor() => self::TwoFactor,
            ! $user->hasCompletedProfile() => self::Profile,
            default => self::Done,
        };
    }
}

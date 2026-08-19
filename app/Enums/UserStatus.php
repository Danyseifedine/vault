<?php

namespace App\Enums;

enum UserStatus: string
{
    /** Seat reserved by an invitation; the account is inert until claimed. */
    case Invited = 'invited';

    /** Onboarding finished - password, 2FA and profile all in place. */
    case Active = 'active';
}

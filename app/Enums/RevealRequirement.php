<?php

namespace App\Enums;

/**
 * What a reveal costs, per environment × sensitivity. Lets dev run open while
 * prod demands everything.
 */
enum RevealRequirement: string
{
    case None = 'none';
    case Pin = 'pin';
    case PinAndPassword = 'pin_password';

    public function requiresPin(): bool
    {
        return $this !== self::None;
    }

    public function requiresPassword(): bool
    {
        return $this === self::PinAndPassword;
    }
}

<?php

namespace App\Enums;

/**
 * How much damage this value does if it leaks. Drives how hard it is to
 * reveal, via each environment's reveal policy.
 */
enum Sensitivity: string
{
    case Critical = 'critical';
    case Sensitive = 'sensitive';
    case Public = 'public';

    /** The default requirement seeded for a brand-new environment. */
    public function defaultRequirement(): RevealRequirement
    {
        return match ($this) {
            self::Critical => RevealRequirement::PinAndPassword,
            self::Sensitive => RevealRequirement::Pin,
            self::Public => RevealRequirement::None,
        };
    }
}

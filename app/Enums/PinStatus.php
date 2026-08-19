<?php

namespace App\Enums;

enum PinStatus: string
{
    case Active = 'active';

    /** Blocked PINs stop reveals immediately, without disabling the account. */
    case Blocked = 'blocked';
}

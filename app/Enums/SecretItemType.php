<?php

namespace App\Enums;

/**
 * What a stored item actually is - the personal vault and the organization's
 * shared vault both hold these two kinds of thing.
 */
enum SecretItemType: string
{
    case Secret = 'secret';

    /** A .pem, a certificate, a keyfile - encrypted before it touches disk. */
    case File = 'file';
}

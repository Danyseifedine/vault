<?php

namespace App\Enums;

enum ChangeSafety: string
{
    case Safe = 'safe';

    /** Rotating this breaks running services until they are redeployed. */
    case Breaking = 'breaking';
}

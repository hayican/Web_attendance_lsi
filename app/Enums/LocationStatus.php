<?php

namespace App\Enums;

enum LocationStatus: string
{
    case ACTIVE = 'ACTIVE';
    case PENDING_VERIFICATION = 'PENDING_VERIFICATION';
    case INACTIVE = 'INACTIVE';
}

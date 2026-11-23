<?php

namespace App\Enums;

enum LeaveStatus: string
{
    case PENDING = 'PENDING';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';
    case PARTIALLY_CANCELLED = 'PARTIALLY_CANCELLED';
    case CANCELLED = 'CANCELLED';
}

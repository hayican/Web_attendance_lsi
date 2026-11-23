<?php

namespace App\Enums;

enum VisitStatus: string
{
    case IN_PROGRESS = 'IN_PROGRESS';
    case COMPLETED = 'COMPLETED';
    case CANCELLED = 'CANCELLED';
    case AUTO_CLOSED = 'AUTO_CLOSED';
}

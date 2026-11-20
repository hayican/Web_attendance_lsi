<?php

namespace App\Enums;

enum ScheduleSource: string
{
    case PATTERN = 'PATTERN';
    case MANUAL_OVERRIDE = 'MANUAL_OVERRIDE';
}

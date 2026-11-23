<?php

namespace App\Enums;

enum HolidayType: string
{
    case NATIONAL = 'NATIONAL';
    case LOCAL = 'LOCAL';
    case COLLECTIVE_LEAVE = 'COLLECTIVE_LEAVE';
}

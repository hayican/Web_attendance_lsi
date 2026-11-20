<?php

namespace App\Enums;

enum LeaveType: string
{
    case CUTI = 'CUTI';
    case SAKIT = 'SAKIT';
    case IZIN = 'IZIN';
    case DINAS_LUAR = 'DINAS_LUAR';
}

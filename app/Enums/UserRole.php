<?php

namespace App\Enums;

enum UserRole: string
{
    case EMPLOYEE_OFFICE = 'EMPLOYEE_OFFICE';
    case EMPLOYEE_SALES = 'EMPLOYEE_SALES';
    case HR_ADMIN = 'HR_ADMIN';
}

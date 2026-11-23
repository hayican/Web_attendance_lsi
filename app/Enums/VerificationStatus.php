<?php

namespace App\Enums;

enum VerificationStatus: string
{
    case AUTO_APPROVED = 'AUTO_APPROVED';
    case PENDING_REVIEW = 'PENDING_REVIEW';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';
}

<?php

declare(strict_types=1);

namespace App\Enums;

enum KycState: string
{
    case NotStarted = 'not_started';
    case Pending = 'pending';
    case UnderReview = 'under_review';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case ReverificationRequired = 'reverification_required';
}

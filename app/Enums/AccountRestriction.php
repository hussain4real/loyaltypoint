<?php

declare(strict_types=1);

namespace App\Enums;

enum AccountRestriction: string
{
    case None = 'none';
    case Suspended = 'suspended';
    case Offboarded = 'offboarded';
}

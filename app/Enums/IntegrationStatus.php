<?php

declare(strict_types=1);

namespace App\Enums;

enum IntegrationStatus
{
    case Pending;
    case Unknown;
    case Succeeded;
    case Rejected;
}

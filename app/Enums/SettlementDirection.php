<?php

declare(strict_types=1);

namespace App\Enums;

enum SettlementDirection: string
{
    case Payable = 'payable';
    case Receivable = 'receivable';
}

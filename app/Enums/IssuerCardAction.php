<?php

declare(strict_types=1);

namespace App\Enums;

enum IssuerCardAction: string
{
    case Freeze = 'freeze';
    case Unfreeze = 'unfreeze';
    case Close = 'close';
    case Reissue = 'reissue';
}

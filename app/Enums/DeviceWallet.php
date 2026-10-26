<?php

declare(strict_types=1);

namespace App\Enums;

enum DeviceWallet: string
{
    case ApplePay = 'apple_pay';
    case GooglePay = 'google_pay';
}

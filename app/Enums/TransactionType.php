<?php

declare(strict_types=1);

namespace App\Enums;

enum TransactionType: string
{
    case Earn = 'earn';
    case Redeem = 'redeem';
    case Bonus = 'bonus';
    case Adjustment = 'adjustment';
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';

    public function isCredit(): bool
    {
        return match ($this) {
            self::Earn, self::Bonus, self::TransferIn => true,
            self::Redeem, self::TransferOut => false,
            self::Adjustment => false, // Adjustments can be either, determined by points sign
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Earn => 'Points Earned',
            self::Redeem => 'Points Redeemed',
            self::Bonus => 'Bonus Points',
            self::Adjustment => 'Point Adjustment',
            self::TransferOut => 'Points Transferred Out',
            self::TransferIn => 'Points Transferred In',
        };
    }
}

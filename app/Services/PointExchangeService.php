<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\PointTransaction;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PointExchangeService
{
    /**
     * Exchange points from one provider to another.
     *
     * @return array{points_sent: int, fee_deducted: int, points_received: int, transfer_out: PointTransaction, transfer_in: PointTransaction}
     *
     * @throws \InvalidArgumentException When validation fails
     */
    public function exchange(
        User $user,
        Provider $fromProvider,
        Provider $toProvider,
        int $points,
    ): array {
        if ($points <= 0) {
            throw new \InvalidArgumentException('Points must be a positive integer.');
        }

        if ($fromProvider->id === $toProvider->id) {
            throw new \InvalidArgumentException('Cannot exchange points within the same provider.');
        }

        if (! $fromProvider->is_active) {
            throw new \InvalidArgumentException('Source provider is not active.');
        }

        if (! $toProvider->is_active) {
            throw new \InvalidArgumentException('Destination provider is not active.');
        }

        return DB::transaction(function () use ($user, $fromProvider, $toProvider, $points): array {
            // Lock both balance rows to prevent race conditions
            $fromBalance = $user->getOrCreateProviderBalance($fromProvider);
            $fromBalance->lockForUpdate();

            $toBalance = $user->getOrCreateProviderBalance($toProvider);
            $toBalance->lockForUpdate();

            // Validate sufficient balance
            if ($points > $fromBalance->balance) {
                throw new \InvalidArgumentException('Insufficient points balance.');
            }

            // Calculate fee and net points
            $feePercent = (float) $fromProvider->exchange_fee_percent;
            $feeAmount = (int) floor($points * $feePercent / 100);
            $netPoints = $points - $feeAmount;

            // Calculate converted points using exchange rates
            // Formula: (netPoints * fromRate) / toRate
            $fromRate = (float) $fromProvider->exchange_rate_base;
            $toRate = (float) $toProvider->exchange_rate_base;
            $convertedPoints = (int) floor($netPoints * $fromRate / $toRate);

            if ($convertedPoints <= 0) {
                throw new \InvalidArgumentException('Exchange would result in zero points. Try a larger amount.');
            }

            // Deduct full points from source provider
            $newFromBalance = $fromBalance->balance - $points;
            $fromBalance->update(['balance' => $newFromBalance]);

            // Add converted points to destination provider
            $newToBalance = $toBalance->balance + $convertedPoints;
            $toBalance->update(['balance' => $newToBalance]);

            // Create transfer out transaction
            $transferOut = PointTransaction::create([
                'user_id' => $user->id,
                'provider_id' => $fromProvider->id,
                'type' => TransactionType::TransferOut,
                'points' => -$points,
                'balance_after' => $newFromBalance,
                'description' => "Transfer to {$toProvider->name}",
                'metadata' => [
                    'to_provider_id' => $toProvider->id,
                    'to_provider_slug' => $toProvider->slug,
                    'points_sent' => $points,
                    'fee_deducted' => $feeAmount,
                    'fee_percent' => $feePercent,
                    'points_after_fee' => $netPoints,
                    'exchange_rate_from' => $fromRate,
                    'exchange_rate_to' => $toRate,
                    'points_received' => $convertedPoints,
                ],
            ]);

            // Create transfer in transaction
            $transferIn = PointTransaction::create([
                'user_id' => $user->id,
                'provider_id' => $toProvider->id,
                'type' => TransactionType::TransferIn,
                'points' => $convertedPoints,
                'balance_after' => $newToBalance,
                'description' => "Transfer from {$fromProvider->name}",
                'metadata' => [
                    'from_provider_id' => $fromProvider->id,
                    'from_provider_slug' => $fromProvider->slug,
                    'original_points' => $points,
                    'fee_deducted' => $feeAmount,
                    'exchange_rate_from' => $fromRate,
                    'exchange_rate_to' => $toRate,
                ],
            ]);

            return [
                'points_sent' => $points,
                'fee_deducted' => $feeAmount,
                'points_received' => $convertedPoints,
                'transfer_out' => $transferOut,
                'transfer_in' => $transferIn,
            ];
        });
    }

    /**
     * Preview an exchange without executing it.
     *
     * @return array{points_to_send: int, fee_amount: int, fee_percent: float, points_after_fee: int, points_to_receive: int, exchange_rate_from: float, exchange_rate_to: float}
     */
    public function preview(
        User $user,
        Provider $fromProvider,
        Provider $toProvider,
        int $points,
    ): array {
        if ($points <= 0) {
            throw new \InvalidArgumentException('Points must be a positive integer.');
        }

        if ($fromProvider->id === $toProvider->id) {
            throw new \InvalidArgumentException('Cannot exchange points within the same provider.');
        }

        $currentBalance = $user->getBalanceForProvider($fromProvider);

        $feePercent = (float) $fromProvider->exchange_fee_percent;
        $feeAmount = (int) floor($points * $feePercent / 100);
        $netPoints = $points - $feeAmount;

        $fromRate = (float) $fromProvider->exchange_rate_base;
        $toRate = (float) $toProvider->exchange_rate_base;
        $convertedPoints = (int) floor($netPoints * $fromRate / $toRate);

        return [
            'points_to_send' => $points,
            'current_balance' => $currentBalance,
            'sufficient_balance' => $currentBalance >= $points,
            'fee_amount' => $feeAmount,
            'fee_percent' => $feePercent,
            'points_after_fee' => $netPoints,
            'points_to_receive' => $convertedPoints,
            'exchange_rate_from' => $fromRate,
            'exchange_rate_to' => $toRate,
        ];
    }
}

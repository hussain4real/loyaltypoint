<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\PointTransaction;
use App\Models\Provider;
use App\Models\User;
use App\Models\VendorUserLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VendorExchangeService
{
    /**
     * Exchange points between accounts linked to the same vendor email.
     *
     * This allows cross-account exchanges where the source and destination
     * can belong to different platform users, as long as both are linked
     * to the same vendor email.
     *
     * @return array{points_sent: int, gross_value: float, total_fee_percent: float, total_fee_value: float, net_value: float, points_received: int, transfer_out: PointTransaction, transfer_in: PointTransaction}
     *
     * @throws \InvalidArgumentException When validation fails
     */
    public function exchange(
        string $vendorEmail,
        Provider $fromProvider,
        Provider $toProvider,
        int $points,
    ): array {
        // Find linked accounts
        $fromLink = $this->findLink($vendorEmail, $fromProvider);
        $toLink = $this->findLink($vendorEmail, $toProvider);

        if (! $fromLink) {
            throw new \InvalidArgumentException('No linked account found for the source provider.');
        }

        if (! $toLink) {
            throw new \InvalidArgumentException('No linked account found for the destination provider.');
        }

        $fromUser = $fromLink->user;
        $toUser = $toLink->user;

        $this->validateExchange($fromProvider, $toProvider, $points);

        return DB::transaction(function () use ($fromUser, $toUser, $fromProvider, $toProvider, $points): array {
            // Lock both balance rows to prevent race conditions
            $fromBalance = $fromUser->getOrCreateProviderBalance($fromProvider);
            $fromBalance->lockForUpdate();

            $toBalance = $toUser->getOrCreateProviderBalance($toProvider);
            $toBalance->lockForUpdate();

            // Validate sufficient balance
            if ($points > $fromBalance->balance) {
                throw new \InvalidArgumentException('Insufficient points balance for exchange.');
            }

            // Calculate exchange using value-based logic
            $calculation = $this->calculateExchange($fromProvider, $toProvider, $points);

            if ($calculation['points_received'] <= 0) {
                throw new \InvalidArgumentException('Exchange would result in zero points. Try exchanging more points.');
            }

            // Generate exchange ID to link the transactions
            $exchangeId = Str::uuid()->toString();

            // Deduct full points from source provider
            $newFromBalance = $fromBalance->balance - $points;
            $fromBalance->update(['balance' => $newFromBalance]);

            // Add converted points to destination provider
            $newToBalance = $toBalance->balance + $calculation['points_received'];
            $toBalance->update(['balance' => $newToBalance]);

            // Create transfer out transaction
            $transferOut = PointTransaction::create([
                'user_id' => $fromUser->id,
                'provider_id' => $fromProvider->id,
                'type' => TransactionType::TransferOut,
                'points' => -$points,
                'balance_after' => $newFromBalance,
                'description' => "Transfer to {$toProvider->name}",
                'metadata' => [
                    'exchange_id' => $exchangeId,
                    'vendor_exchange' => true,
                    'to_provider_id' => $toProvider->id,
                    'to_provider_slug' => $toProvider->slug,
                    'to_user_id' => $toUser->id,
                    'points_sent' => $points,
                    'gross_value' => $calculation['gross_value'],
                    'total_fee_percent' => $calculation['total_fee_percent'],
                    'total_fee_value' => $calculation['total_fee_value'],
                    'net_value' => $calculation['net_value'],
                    'points_received' => $calculation['points_received'],
                ],
            ]);

            // Create transfer in transaction
            $transferIn = PointTransaction::create([
                'user_id' => $toUser->id,
                'provider_id' => $toProvider->id,
                'type' => TransactionType::TransferIn,
                'points' => $calculation['points_received'],
                'balance_after' => $newToBalance,
                'description' => "Transfer from {$fromProvider->name}",
                'metadata' => [
                    'exchange_id' => $exchangeId,
                    'vendor_exchange' => true,
                    'from_provider_id' => $fromProvider->id,
                    'from_provider_slug' => $fromProvider->slug,
                    'from_user_id' => $fromUser->id,
                    'original_points' => $points,
                    'gross_value' => $calculation['gross_value'],
                    'total_fee_percent' => $calculation['total_fee_percent'],
                    'total_fee_value' => $calculation['total_fee_value'],
                    'net_value' => $calculation['net_value'],
                ],
            ]);

            return [
                'points_sent' => $points,
                'gross_value' => $calculation['gross_value'],
                'total_fee_percent' => $calculation['total_fee_percent'],
                'total_fee_value' => $calculation['total_fee_value'],
                'net_value' => $calculation['net_value'],
                'points_received' => $calculation['points_received'],
                'transfer_out' => $transferOut,
                'transfer_in' => $transferIn,
            ];
        });
    }

    /**
     * Preview a vendor exchange without executing it.
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException
     */
    public function preview(
        string $vendorEmail,
        Provider $fromProvider,
        Provider $toProvider,
        int $points,
    ): array {
        // Find linked accounts
        $fromLink = $this->findLink($vendorEmail, $fromProvider);
        $toLink = $this->findLink($vendorEmail, $toProvider);

        if (! $fromLink) {
            throw new \InvalidArgumentException('No linked account found for the source provider.');
        }

        if (! $toLink) {
            throw new \InvalidArgumentException('No linked account found for the destination provider.');
        }

        $fromUser = $fromLink->user;
        $toUser = $toLink->user;

        $this->validateExchange($fromProvider, $toProvider, $points);

        $currentBalance = $fromUser->getBalanceForProvider($fromProvider);
        $calculation = $this->calculateExchange($fromProvider, $toProvider, $points);

        return [
            // Input
            'points_to_send' => $points,
            'from_provider' => [
                'slug' => $fromProvider->slug,
                'name' => $fromProvider->name,
                'points_to_value_ratio' => $calculation['source_points_to_value_ratio'],
                'transfer_fee_percent' => $calculation['source_fee_percent'],
            ],
            'to_provider' => [
                'slug' => $toProvider->slug,
                'name' => $toProvider->name,
                'points_to_value_ratio' => $calculation['destination_points_to_value_ratio'],
                'transfer_fee_percent' => $calculation['destination_fee_percent'],
            ],

            // Accounts involved
            'from_account' => [
                'id' => $fromUser->id,
                'name' => $fromUser->name,
                'email' => $fromUser->email,
            ],
            'to_account' => [
                'id' => $toUser->id,
                'name' => $toUser->name,
                'email' => $toUser->email,
            ],

            // Balance check
            'current_balance' => $currentBalance,
            'sufficient_balance' => $currentBalance >= $points,

            // Value calculation
            'gross_value' => $calculation['gross_value'],

            // Fee breakdown
            'fees' => [
                'source_provider_fee' => [
                    'percent' => $calculation['source_fee_percent'],
                    'value' => $calculation['source_fee_value'],
                ],
                'destination_provider_fee' => [
                    'percent' => $calculation['destination_fee_percent'],
                    'value' => $calculation['destination_fee_value'],
                ],
                'app_fee' => [
                    'percent' => $calculation['app_fee_percent'],
                    'value' => $calculation['app_fee_value'],
                ],
                'total' => [
                    'percent' => $calculation['total_fee_percent'],
                    'value' => $calculation['total_fee_value'],
                ],
            ],

            // Result
            'net_value' => $calculation['net_value'],
            'points_to_receive' => $calculation['points_received'],
        ];
    }

    /**
     * Find a vendor user link for the given email and provider.
     */
    private function findLink(string $vendorEmail, Provider $provider): ?VendorUserLink
    {
        return VendorUserLink::where('vendor_email', $vendorEmail)
            ->where('provider_id', $provider->id)
            ->with('user')
            ->first();
    }

    /**
     * Calculate the exchange values and fees.
     *
     * @return array<string, float|int>
     */
    private function calculateExchange(Provider $fromProvider, Provider $toProvider, int $points): array
    {
        // Get ratios and fees
        $sourceRatio = (float) $fromProvider->points_to_value_ratio;
        $destRatio = (float) $toProvider->points_to_value_ratio;
        $sourceFeePercent = (float) $fromProvider->transfer_fee_percent;
        $destFeePercent = (float) $toProvider->transfer_fee_percent;
        $appFeePercent = (float) config('services.loyalty.app_transfer_fee_percent', 5.0);

        // Step 1: Calculate gross value from points
        $grossValue = $points * $sourceRatio;

        // Step 2: Calculate total fee percentage and value
        $totalFeePercent = $sourceFeePercent + $destFeePercent + $appFeePercent;
        $totalFeeValue = round($grossValue * $totalFeePercent / 100, 2);

        // Calculate individual fee values for breakdown
        $sourceFeeValue = round($grossValue * $sourceFeePercent / 100, 2);
        $destFeeValue = round($grossValue * $destFeePercent / 100, 2);
        $appFeeValue = round($grossValue * $appFeePercent / 100, 2);

        // Step 3: Calculate net value after fees
        $netValue = round($grossValue - $totalFeeValue, 2);

        // Step 4: Convert net value to destination points
        $pointsReceived = $destRatio > 0 ? (int) floor($netValue / $destRatio) : 0;

        return [
            'source_points_to_value_ratio' => $sourceRatio,
            'destination_points_to_value_ratio' => $destRatio,
            'source_fee_percent' => $sourceFeePercent,
            'destination_fee_percent' => $destFeePercent,
            'app_fee_percent' => $appFeePercent,
            'gross_value' => round($grossValue, 2),
            'source_fee_value' => $sourceFeeValue,
            'destination_fee_value' => $destFeeValue,
            'app_fee_value' => $appFeeValue,
            'total_fee_percent' => $totalFeePercent,
            'total_fee_value' => $totalFeeValue,
            'net_value' => $netValue,
            'points_received' => $pointsReceived,
        ];
    }

    /**
     * Validate exchange parameters.
     *
     * @throws \InvalidArgumentException
     */
    private function validateExchange(Provider $fromProvider, Provider $toProvider, int $points): void
    {
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
    }
}

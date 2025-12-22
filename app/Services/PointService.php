<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\PointTransaction;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PointService
{
    /**
     * Award points to a user for a specific provider (earn transaction).
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public function awardPoints(
        User $user,
        Provider $provider,
        int $points,
        string $description,
        TransactionType $type = TransactionType::Earn,
        ?array $metadata = null,
        ?\DateTimeInterface $expiresAt = null,
    ): PointTransaction {
        if ($points <= 0) {
            throw new \InvalidArgumentException('Points must be a positive integer.');
        }

        return DB::transaction(function () use ($user, $provider, $points, $description, $type, $metadata, $expiresAt): PointTransaction {
            // Lock the user's provider balance row for update
            $userBalance = $user->getOrCreateProviderBalance($provider);
            $userBalance->lockForUpdate();

            $currentBalance = $userBalance->balance;
            $newBalance = $currentBalance + $points;

            // Update the cached balance
            $userBalance->update(['balance' => $newBalance]);

            return PointTransaction::create([
                'user_id' => $user->id,
                'provider_id' => $provider->id,
                'type' => $type,
                'points' => $points,
                'balance_after' => $newBalance,
                'description' => $description,
                'metadata' => $metadata,
                'expires_at' => $expiresAt,
            ]);
        });
    }

    /**
     * Deduct points from a user for a specific provider (redeem transaction).
     *
     * @param  array<string, mixed>|null  $metadata
     *
     * @throws \InvalidArgumentException When points are negative or exceed balance
     */
    public function deductPoints(
        User $user,
        Provider $provider,
        int $points,
        string $description,
        TransactionType $type = TransactionType::Redeem,
        ?array $metadata = null,
    ): PointTransaction {
        if ($points <= 0) {
            throw new \InvalidArgumentException('Points must be a positive integer.');
        }

        return DB::transaction(function () use ($user, $provider, $points, $description, $type, $metadata): PointTransaction {
            // Lock the user's provider balance row for update
            $userBalance = $user->getOrCreateProviderBalance($provider);
            $userBalance->lockForUpdate();

            $currentBalance = $userBalance->balance;

            if ($points > $currentBalance) {
                throw new \InvalidArgumentException('Insufficient points balance.');
            }

            $newBalance = $currentBalance - $points;

            // Update the cached balance
            $userBalance->update(['balance' => $newBalance]);

            return PointTransaction::create([
                'user_id' => $user->id,
                'provider_id' => $provider->id,
                'type' => $type,
                'points' => -$points, // Negative for deductions
                'balance_after' => $newBalance,
                'description' => $description,
                'metadata' => $metadata,
            ]);
        });
    }

    /**
     * Award bonus points to a user for a specific provider.
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public function awardBonusPoints(
        User $user,
        Provider $provider,
        int $points,
        string $description,
        ?array $metadata = null,
    ): PointTransaction {
        return $this->awardPoints(
            user: $user,
            provider: $provider,
            points: $points,
            description: $description,
            type: TransactionType::Bonus,
            metadata: $metadata,
        );
    }

    /**
     * Make an adjustment to a user's points for a specific provider (can be positive or negative).
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public function adjustPoints(
        User $user,
        Provider $provider,
        int $points,
        string $description,
        ?array $metadata = null,
    ): PointTransaction {
        if ($points === 0) {
            throw new \InvalidArgumentException('Adjustment points cannot be zero.');
        }

        if ($points > 0) {
            return $this->awardPoints(
                user: $user,
                provider: $provider,
                points: $points,
                description: $description,
                type: TransactionType::Adjustment,
                metadata: $metadata,
            );
        }

        return $this->deductPoints(
            user: $user,
            provider: $provider,
            points: abs($points),
            description: $description,
            type: TransactionType::Adjustment,
            metadata: $metadata,
        );
    }

    /**
     * Get a user's balance for a specific provider.
     */
    public function getBalance(User $user, Provider $provider): int
    {
        return $user->getBalanceForProvider($provider);
    }
}

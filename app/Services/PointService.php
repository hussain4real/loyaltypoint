<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\PointTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PointService
{
    /**
     * Award points to a user (earn transaction).
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public function awardPoints(
        User $user,
        int $points,
        string $description,
        TransactionType $type = TransactionType::Earn,
        ?array $metadata = null,
        ?\DateTimeInterface $expiresAt = null,
    ): PointTransaction {
        if ($points <= 0) {
            throw new \InvalidArgumentException('Points must be a positive integer.');
        }

        return DB::transaction(function () use ($user, $points, $description, $type, $metadata, $expiresAt): PointTransaction {
            $user->lockForUpdate();

            $currentBalance = $user->point_balance;
            $newBalance = $currentBalance + $points;

            return PointTransaction::create([
                'user_id' => $user->id,
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
     * Deduct points from a user (redeem transaction).
     *
     * @param  array<string, mixed>|null  $metadata
     *
     * @throws \InvalidArgumentException When points are negative or exceed balance
     */
    public function deductPoints(
        User $user,
        int $points,
        string $description,
        TransactionType $type = TransactionType::Redeem,
        ?array $metadata = null,
    ): PointTransaction {
        if ($points <= 0) {
            throw new \InvalidArgumentException('Points must be a positive integer.');
        }

        return DB::transaction(function () use ($user, $points, $description, $type, $metadata): PointTransaction {
            $user->lockForUpdate();

            $currentBalance = $user->point_balance;

            if ($points > $currentBalance) {
                throw new \InvalidArgumentException('Insufficient points balance.');
            }

            $newBalance = $currentBalance - $points;

            return PointTransaction::create([
                'user_id' => $user->id,
                'type' => $type,
                'points' => -$points, // Negative for deductions
                'balance_after' => $newBalance,
                'description' => $description,
                'metadata' => $metadata,
            ]);
        });
    }

    /**
     * Award bonus points to a user.
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public function awardBonusPoints(
        User $user,
        int $points,
        string $description,
        ?array $metadata = null,
    ): PointTransaction {
        return $this->awardPoints(
            user: $user,
            points: $points,
            description: $description,
            type: TransactionType::Bonus,
            metadata: $metadata,
        );
    }

    /**
     * Make an adjustment to a user's points (can be positive or negative).
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public function adjustPoints(
        User $user,
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
                points: $points,
                description: $description,
                type: TransactionType::Adjustment,
                metadata: $metadata,
            );
        }

        return $this->deductPoints(
            user: $user,
            points: abs($points),
            description: $description,
            type: TransactionType::Adjustment,
            metadata: $metadata,
        );
    }
}

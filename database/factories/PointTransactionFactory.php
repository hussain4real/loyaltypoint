<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TransactionType;
use App\Models\PointTransaction;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PointTransaction>
 */
class PointTransactionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $sequence = $this->faker->unique()->numberBetween(1, 999999);

        return [
            'user_id' => User::factory(),
            'provider_id' => Provider::factory(),
            'type' => TransactionType::Earn,
            'points' => 100,
            'balance_after' => 100,
            'description' => "Transaction #{$sequence}",
            'metadata' => null,
            'expires_at' => null,
        ];
    }

    /**
     * Set the provider for the transaction.
     */
    public function forProvider(Provider $provider): static
    {
        return $this->state(fn (): array => [
            'provider_id' => $provider->id,
        ]);
    }

    /**
     * Create an earn transaction.
     */
    public function earn(int $points = 100): static
    {
        return $this->state(fn (): array => [
            'type' => TransactionType::Earn,
            'points' => $points,
        ]);
    }

    /**
     * Create a redeem transaction.
     */
    public function redeem(int $points = 100): static
    {
        return $this->state(fn (): array => [
            'type' => TransactionType::Redeem,
            'points' => -abs($points),
        ]);
    }

    /**
     * Create a bonus transaction.
     */
    public function bonus(int $points = 100): static
    {
        return $this->state(fn (): array => [
            'type' => TransactionType::Bonus,
            'points' => $points,
        ]);
    }

    /**
     * Create an adjustment transaction.
     */
    public function adjustment(int $points = 0): static
    {
        return $this->state(fn (): array => [
            'type' => TransactionType::Adjustment,
            'points' => $points,
        ]);
    }

    /**
     * Create a transfer out transaction.
     */
    public function transferOut(int $points = 100): static
    {
        return $this->state(fn (): array => [
            'type' => TransactionType::TransferOut,
            'points' => -abs($points),
        ]);
    }

    /**
     * Create a transfer in transaction.
     */
    public function transferIn(int $points = 100): static
    {
        return $this->state(fn (): array => [
            'type' => TransactionType::TransferIn,
            'points' => $points,
        ]);
    }

    /**
     * Set the balance after transaction.
     */
    public function withBalance(int $balance): static
    {
        return $this->state(fn (): array => [
            'balance_after' => $balance,
        ]);
    }

    /**
     * Set expiration date.
     */
    public function expiresAt(\DateTimeInterface $date): static
    {
        return $this->state(fn (): array => [
            'expires_at' => $date,
        ]);
    }
}

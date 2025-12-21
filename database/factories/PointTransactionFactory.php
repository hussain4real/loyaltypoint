<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TransactionType;
use App\Models\PointTransaction;
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
        return [
            'user_id' => User::factory(),
            'type' => $this->faker->randomElement(TransactionType::cases()),
            'points' => $this->faker->numberBetween(10, 500),
            'balance_after' => $this->faker->numberBetween(0, 10000),
            'description' => $this->faker->sentence(),
            'metadata' => null,
            'expires_at' => null,
        ];
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

<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Provider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Provider>
 */
class ProviderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $sequence = $this->faker->unique()->numberBetween(1, 999999);
        $name = "Provider {$sequence}";

        return [
            'name' => $name,
            'trade_name' => null,
            'slug' => Str::slug($name),
            'category' => null,
            'description' => null,
            'official_logo' => null,
            'web_link' => null,
            'is_active' => true,
            'points_to_value_ratio' => 1.0000,
            'transfer_fee_percent' => 0.00,
            'metadata' => null,
        ];
    }

    /**
     * Create a provider with specific name.
     */
    public function named(string $name): static
    {
        return $this->state(fn (): array => [
            'name' => $name,
            'slug' => Str::slug($name),
        ]);
    }

    /**
     * Mark the provider as inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (): array => [
            'is_active' => false,
        ]);
    }

    /**
     * Set a custom points to value ratio.
     */
    public function withPointsToValueRatio(float $ratio): static
    {
        return $this->state(fn (): array => [
            'points_to_value_ratio' => $ratio,
        ]);
    }

    /**
     * Set a custom transfer fee percentage.
     */
    public function withTransferFee(float $percent): static
    {
        return $this->state(fn (): array => [
            'transfer_fee_percent' => $percent,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Otp;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Otp>
 */
class OtpFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $sequence = $this->faker->unique()->numberBetween(0, 999999);

        return [
            'user_id' => User::factory(),
            'code' => str_pad((string) $sequence, 6, '0', STR_PAD_LEFT),
            'purpose' => 'vendor_auth',
            'expires_at' => now()->addMinutes(10),
            'verified_at' => null,
            'attempts' => 0,
        ];
    }

    /**
     * Indicate that the OTP is expired.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'expires_at' => now()->subMinutes(5),
        ]);
    }

    /**
     * Indicate that the OTP is verified.
     */
    public function verified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'verified_at' => now(),
        ]);
    }

    /**
     * Set a specific purpose.
     */
    public function forPurpose(string $purpose): static
    {
        return $this->state(fn (array $attributes): array => [
            'purpose' => $purpose,
        ]);
    }

    /**
     * Set max attempts.
     */
    public function maxAttempts(): static
    {
        return $this->state(fn (array $attributes): array => [
            'attempts' => 3,
        ]);
    }
}

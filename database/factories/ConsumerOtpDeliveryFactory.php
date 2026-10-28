<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ConsumerChallenge;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ConsumerOtpDeliveryFactory extends Factory
{
    public function definition(): array
    {
        return ['id' => (string) Str::uuid(), 'challenge_id' => ConsumerChallenge::factory(), 'payload' => ['email' => 'consumer@example.invalid', 'code' => '123456'], 'state' => 'pending', 'attempts' => 0, 'available_at' => now(), 'expires_at' => now()->addMinutes(5)];
    }
}

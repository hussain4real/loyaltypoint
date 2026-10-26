<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ConsumerChallengeFactory extends Factory
{
    public function definition(): array
    {
        return ['id' => (string) Str::uuid(), 'purpose' => 'contact_verification', 'method' => 'verified_channel_otp', 'proof_hash' => Hash::make('123456'), 'expires_at' => now()->addMinutes(5), 'attempts' => 0];
    }
}

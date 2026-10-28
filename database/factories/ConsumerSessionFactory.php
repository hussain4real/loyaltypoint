<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ConsumerDevice;
use App\Models\ConsumerSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ConsumerSession> */
class ConsumerSessionFactory extends Factory
{
    public function definition(): array
    {
        return ['reference' => (string) Str::uuid(), 'device_id' => ConsumerDevice::factory(),
            'consumer_identity_id' => fn (array $attributes): int => ConsumerDevice::findOrFail($attributes['device_id'])->consumer_identity_id,
            'user_id' => fn (array $attributes): int => ConsumerDevice::findOrFail($attributes['device_id'])->user_id,
            'personal_access_token_id' => fn (array $attributes): int => User::findOrFail($attributes['user_id'])->createToken('Consumer fixture', ['consumer:identity'], now()->addHour())->accessToken->id,
            'auth_method' => 'password', 'authenticated_at' => now(), 'last_active_at' => now(), 'expires_at' => now()->addHour()];
    }
}

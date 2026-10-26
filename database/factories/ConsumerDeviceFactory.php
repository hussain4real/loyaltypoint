<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ConsumerDevice;
use App\Models\ConsumerIdentity;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ConsumerDevice> */
class ConsumerDeviceFactory extends Factory
{
    public function definition(): array
    {
        return ['reference' => (string) Str::uuid(), 'consumer_identity_id' => ConsumerIdentity::factory()->contactVerified(),
            'user_id' => fn (array $attributes): int => ConsumerIdentity::findOrFail($attributes['consumer_identity_id'])->user_id,
            'assurance' => 'password'];
    }
}

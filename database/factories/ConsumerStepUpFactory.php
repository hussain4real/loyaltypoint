<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ConsumerSession;
use App\Models\ConsumerStepUp;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ConsumerStepUp> */
class ConsumerStepUpFactory extends Factory
{
    public function definition(): array
    {
        return ['reference' => (string) Str::uuid(), 'consumer_session_id' => ConsumerSession::factory(),
            'device_id' => fn (array $attributes): int => ConsumerSession::findOrFail($attributes['consumer_session_id'])->device_id,
            'challenge_reference' => (string) Str::uuid(), 'action_digest' => hash('sha256', 'example-action'), 'expires_at' => now()->addMinutes(5)];
    }
}

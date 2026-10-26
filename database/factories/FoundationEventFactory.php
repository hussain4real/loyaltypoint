<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FoundationEvent;
use App\Services\FoundationIdempotency;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<FoundationEvent> */
class FoundationEventFactory extends Factory
{
    public function definition(): array
    {
        $event = (string) Str::uuid();
        $payload = ['event_id' => $event, 'operation_reference' => 'operation:'.$event, 'status' => 'succeeded', 'occurred_at' => now()->toIso8601String()];

        return ['id' => (string) Str::uuid(), 'identity_hash' => hash('sha256', 'sandbox-partner|'.$event), 'source' => 'sandbox-partner', 'external_event_id' => $event,
            'payload' => $payload, 'payload_hash' => FoundationIdempotency::fingerprint($payload),
            'state' => 'pending', 'attempts' => 0, 'available_at' => now()];
    }
}

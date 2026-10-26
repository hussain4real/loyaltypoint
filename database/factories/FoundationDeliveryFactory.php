<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FoundationDelivery;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<FoundationDelivery> */
class FoundationDeliveryFactory extends Factory
{
    public function definition(): array
    {
        return ['id' => (string) Str::uuid(), 'user_id' => User::factory(), 'deduplication_key' => hash('sha256', (string) Str::uuid()),
            'payload' => ['template' => 'operation_status', 'reference' => 'operation:test'],
            'state' => 'pending', 'attempts' => 0, 'available_at' => now()];
    }
}

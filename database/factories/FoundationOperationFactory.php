<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FoundationOperation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<FoundationOperation> */
class FoundationOperationFactory extends Factory
{
    public function definition(): array
    {
        return ['identity_hash' => hash('sha256', (string) Str::uuid()), 'source' => 'sandbox-partner', 'reference' => (string) Str::uuid(), 'state' => 'pending', 'observed_at' => now(), 'last_event_id' => (string) Str::uuid()];
    }
}

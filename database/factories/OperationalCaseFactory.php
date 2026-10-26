<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\OperationalCase;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<OperationalCase> */
class OperationalCaseFactory extends Factory
{
    public function definition(): array
    {
        $source = (string) Str::ulid();

        return ['reference' => (string) Str::ulid(), 'category' => 'integration', 'source_reference' => $source, 'partner_id' => null, 'customer_id' => null, 'owner_id' => null, 'owner_team' => 'operations', 'state' => 'open', 'due_at' => now()->addDay()];
    }
}

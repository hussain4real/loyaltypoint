<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CaseEvent;
use App\Models\OperationalCase;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CaseEvent> */
class CaseEventFactory extends Factory
{
    public function definition(): array
    {
        return ['operational_case_id' => OperationalCase::factory(), 'actor_id' => null, 'from_state' => null, 'to_state' => 'open', 'reason' => 'investigation_required', 'evidence_reference' => null, 'owner_id' => null];
    }
}

<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AuditEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AuditEvent> */
class AuditEventFactory extends Factory
{
    public function definition(): array
    {
        return ['reference' => (string) Str::ulid(), 'actor_id' => null, 'action' => 'case.opened', 'target_reference' => (string) Str::ulid(), 'reason' => 'investigation_required', 'partner_id' => null, 'customer_id' => null, 'context' => []];
    }
}

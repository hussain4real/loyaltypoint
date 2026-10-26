<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\StaffRole;
use App\Models\StaffMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StaffMembership> */
class StaffMembershipFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'role' => StaffRole::Support, 'partner_ids' => [], 'customer_ids' => [], 'enabled' => true];
    }

    public function enrolled(): static
    {
        return $this->state(fn (): array => [
            'mfa_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ',
            'mfa_confirmed_at' => now(),
            'enrollment_token_hash' => null,
        ]);
    }
}

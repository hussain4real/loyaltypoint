<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\StaffElevation;
use App\Models\StaffMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StaffElevation> */
class StaffElevationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'staff_membership_id' => StaffMembership::factory(),
            'granted_by' => User::factory(),
            'permission' => 'audit.export',
            'session_binding' => hash('sha256', fake()->uuid()),
            'reason' => 'operational_review',
            'expires_at' => now()->addMinutes(10),
        ];
    }
}

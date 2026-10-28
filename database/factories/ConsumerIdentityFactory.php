<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ConsumerIdentity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ConsumerIdentityFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory()->unverified(), 'reference' => (string) Str::uuid(), 'contact_hash' => fn (array $attributes): string => hash('sha256', mb_strtolower(User::findOrFail($attributes['user_id'])->email)), 'contact_verified_at' => null, 'terms_version' => 'test-terms-v1', 'privacy_notice_version' => 'test-privacy-v1', 'notices_accepted_at' => now(), 'kyc_state' => 'not_started', 'restriction' => 'none', 'verification_revision' => 0];
    }

    public function contactVerified(): static
    {
        return $this->state(fn (): array => ['contact_verified_at' => now()])->afterCreating(function (ConsumerIdentity $identity): void {
            $identity->user->forceFill(['email_verified_at' => $identity->contact_verified_at])->save();
        });
    }
}

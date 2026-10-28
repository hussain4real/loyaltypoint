<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ConsumerEnrollmentIntent;
use App\Models\ConsumerVerificationRevision;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ConsumerEnrollmentIntent> */
class ConsumerEnrollmentIntentFactory extends Factory
{
    public function definition(): array
    {
        return ['reference' => (string) Str::uuid(), 'consumer_verification_revision_id' => ConsumerVerificationRevision::factory(),
            'consumer_identity_id' => fn (array $attributes): int => ConsumerVerificationRevision::findOrFail($attributes['consumer_verification_revision_id'])->consumer_identity_id,
            'purpose' => 'initial_economic_enrollment', 'policy_reference' => 'fixture-policy-v1',
            'payload' => ['source' => 'local-fixture', 'wallet_intent_reference' => 'fixture:wallet-intent', 'card_intent_reference' => 'fixture:card-intent']];
    }
}

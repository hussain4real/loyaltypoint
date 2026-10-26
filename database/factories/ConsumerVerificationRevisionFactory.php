<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\KycState;
use App\Models\ConsumerIdentity;
use App\Models\ConsumerVerificationRevision;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ConsumerVerificationRevision> */
class ConsumerVerificationRevisionFactory extends Factory
{
    public function definition(): array
    {
        return ['reference' => (string) Str::uuid(), 'consumer_identity_id' => ConsumerIdentity::factory()->contactVerified(),
            'customer_id' => fn (array $attributes): int => ConsumerIdentity::findOrFail($attributes['consumer_identity_id'])->user_id,
            'revision' => 1, 'policy_reference' => 'fixture-policy-v1', 'source' => 'local-fixture',
            'operation_reference' => fn (array $attributes): string => 'fixture:'.hash('sha256', $attributes['reference']),
            'idempotency_hash' => hash('sha256', (string) Str::uuid()), 'request_hash' => hash('sha256', (string) Str::uuid()),
            'evidence' => fn (array $attributes): array => ['identity_document_reference' => 'fixture:'.ConsumerIdentity::findOrFail($attributes['consumer_identity_id'])->reference.'/document', 'liveness_evidence_reference' => 'fixture:'.ConsumerIdentity::findOrFail($attributes['consumer_identity_id'])->reference.'/liveness', 'identity_details_reference' => 'fixture:'.ConsumerIdentity::findOrFail($attributes['consumer_identity_id'])->reference.'/details'],
            'checks' => null, 'unresolved_checks' => [], 'state' => KycState::Pending, 'manual_review_required' => false];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (ConsumerVerificationRevision $revision): void {
            ConsumerIdentity::findOrFail($revision->consumer_identity_id)->update(['verification_revision' => $revision->revision, 'kyc_state' => $revision->state, 'policy_reference' => $revision->policy_reference]);
        });
    }
}

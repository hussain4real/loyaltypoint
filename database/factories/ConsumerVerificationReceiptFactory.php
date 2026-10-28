<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ConsumerVerificationReceipt;
use App\Models\ConsumerVerificationRevision;
use App\Services\ConsumerVerificationDecision;
use App\Services\FoundationIdempotency;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ConsumerVerificationReceipt> */
class ConsumerVerificationReceiptFactory extends Factory
{
    public function definition(): array
    {
        return ['reference' => (string) Str::uuid(), 'consumer_verification_revision_id' => ConsumerVerificationRevision::factory(),
            'source' => 'local-fixture', 'event_reference' => (string) Str::uuid(),
            'identity_hash' => fn (array $attributes): string => hash('sha256', $attributes['source'].'|'.$attributes['event_reference']),
            'payload' => function (array $attributes): array {
                $revision = ConsumerVerificationRevision::findOrFail($attributes['consumer_verification_revision_id']);

                return ['event_reference' => $attributes['event_reference'], 'revision_reference' => $revision->reference,
                    'operation_reference' => $revision->operation_reference, 'checks' => array_fill_keys(ConsumerVerificationDecision::CHECKS, 'clear'),
                    'occurred_at' => now()->toIso8601String(), 'evidence_reference' => 'fixture:provider-evidence'];
            },
            'payload_hash' => fn (array $attributes): string => FoundationIdempotency::fingerprint($attributes['payload']),
            'occurred_at' => now()];
    }
}

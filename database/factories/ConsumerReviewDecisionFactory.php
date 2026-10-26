<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ConsumerReviewDecision;
use App\Models\ConsumerVerificationRevision;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ConsumerReviewDecision> */
class ConsumerReviewDecisionFactory extends Factory
{
    public function definition(): array
    {
        return ['reference' => (string) Str::uuid(), 'consumer_verification_revision_id' => ConsumerVerificationRevision::factory(),
            'actor_id' => User::factory(), 'decision' => 'rejected', 'reason' => 'review_required', 'evidence_reference' => 'fixture:review-evidence', 'resolved_checks' => []];
    }
}

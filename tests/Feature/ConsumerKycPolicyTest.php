<?php

declare(strict_types=1);

use App\Enums\KycState;
use App\Enums\StaffRole;
use App\Models\ConsumerEnrollmentIntent;
use App\Models\ConsumerVerificationRevision;
use App\Models\StaffMembership;
use App\Models\User;
use App\Services\ConsumerIdentityFailure;
use App\Services\ConsumerIdentityService;
use App\Services\ConsumerVerificationDecision;
use App\Services\ConsumerVerificationGateway;
use App\Services\LocalConsumerVerificationGateway;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    config(['pull2spend.consumer.enabled' => true, 'pull2spend.consumer.verification_mode' => 'fixture',
        'pull2spend.consumer.verification_key' => str_repeat('fixture', 6), 'pull2spend.consumer.policy_reference' => 'P2-KYC-2026-10-28-v1',
        'pull2spend.consumer.policy_approved' => true, 'pull2spend.consumer.verification_validity_seconds' => 31536000]);
    app()->bind(ConsumerVerificationGateway::class, LocalConsumerVerificationGateway::class);
});

function kycPolicyReviewer(?User $user = null): User
{
    config(['pull2spend.backoffice_enabled' => true]);
    $staff = StaffMembership::factory()->enrolled()->create(['user_id' => $user?->id ?? User::factory(), 'role' => StaffRole::Compliance,
        'customer_ids' => null, 'partner_ids' => null]);
    $session = app('session')->driver();
    $session->start();
    $binding = hash('sha256', $session->getId());
    $staff->forceFill(['session_binding' => $binding])->save();
    $session->put(['staff_mfa_user' => $staff->user_id, 'staff_mfa_at' => now()->timestamp, 'staff_mfa_binding' => $binding]);
    $request = Request::create('/backoffice/identity/reviews');
    $request->setLaravelSession($session);
    $request->setUserResolver(fn () => $staff->user);
    app()->instance('request', $request);
    $request->setUserResolver(fn () => $staff->user);

    return $staff->user;
}

function kycPolicyObservation(ConsumerVerificationRevision $revision, array $changes = []): ConsumerVerificationDecision
{
    return new ConsumerVerificationDecision($revision->source, ['event_reference' => (string) Str::uuid(),
        'revision_reference' => $revision->reference, 'operation_reference' => $revision->operation_reference,
        'checks' => array_replace(array_fill_keys(ConsumerVerificationDecision::CHECKS, 'clear'), $changes),
        'occurred_at' => now()->toIso8601String(), 'evidence_reference' => 'fixture:policy-report']);
}

it('requires an examiner and a different checker even for all clear automated results', function (): void {
    $this->freezeTime();
    $revision = ConsumerVerificationRevision::factory()->create(['policy_reference' => 'P2-KYC-2026-10-28-v1']);
    $service = app(ConsumerIdentityService::class);
    $service->applyProviderDecision(kycPolicyObservation($revision));
    expect($revision->identity->fresh()->kyc_state)->toBe(KycState::UnderReview);
    $examiner = kycPolicyReviewer();
    expect(fn () => app(ConsumerIdentityService::class)->review($examiner, $revision->reference, 1, KycState::Verified, 'checks_reviewed', 'review:checker', recommendationReference: $revision->latestRecommendation()->value('reference')))
        ->toThrow(ConsumerIdentityFailure::class, 'examiner_recommendation_required');
    app(ConsumerIdentityService::class)->recommendApproval($examiner, $revision->reference, 1, 'checks_reviewed', 'review:examiner', [], 'standard', now()->addYears(2)->toDateString());
    $this->assertDatabaseCount('consumer_enrollment_intents', 0);
    expect(fn () => app(ConsumerIdentityService::class)->review($examiner, $revision->reference, 1, KycState::Verified, 'checks_reviewed', 'review:checker', recommendationReference: $revision->latestRecommendation()->value('reference')))
        ->toThrow(ConsumerIdentityFailure::class, 'independent_checker_required');
    $checker = kycPolicyReviewer();
    app(ConsumerIdentityService::class)->review($checker, $revision->reference, 1, KycState::Verified, 'checks_reviewed', 'review:checker', recommendationReference: $revision->latestRecommendation()->value('reference'));
    expect($revision->identity->fresh()->kyc_state)->toBe(KycState::Verified);
    expect($revision->identity->fresh()->expires_at->toDateTimeString() === now()->addDays(365)->toDateTimeString())->toBeTrue();
    $this->assertDatabaseCount('consumer_enrollment_intents', 1);
    $this->assertDatabaseCount('consumer_review_decisions', 2);
    expect(ConsumerEnrollmentIntent::sole()->payload['financial_authority'])->toBeFalse();
    expect(DB::table('consumer_review_decisions')->value('review_context'))->not->toContain('observation_hash');
});

it('caps approved validity by risk and document expiry', function (string $risk, int $expiryDays, int $expectedDays): void {
    $this->freezeTime();
    $revision = ConsumerVerificationRevision::factory()->create(['policy_reference' => 'P2-KYC-2026-10-28-v1']);
    $service = app(ConsumerIdentityService::class);
    $service->applyProviderDecision(kycPolicyObservation($revision));
    $examiner = kycPolicyReviewer();
    app(ConsumerIdentityService::class)->recommendApproval($examiner, $revision->reference, 1, 'checks_reviewed', 'review:examiner', [], $risk, now()->addDays($expiryDays)->toDateString());
    $checker = kycPolicyReviewer();
    app(ConsumerIdentityService::class)->review($checker, $revision->reference, 1, KycState::Verified, 'checks_reviewed', 'review:checker', recommendationReference: $revision->latestRecommendation()->value('reference'));
    $expected = $expiryDays < $expectedDays ? now()->addDays($expiryDays)->endOfDay() : now()->addDays($expectedDays);
    expect($revision->identity->fresh()->expires_at->toDateTimeString() === $expected->toDateTimeString())->toBeTrue();
})->with([['elevated', 500, 90], ['standard', 20, 365]]);

it('invalidates the examiner recommendation when observations or policy change', function (bool $policyChanges): void {
    $this->freezeTime();
    $revision = ConsumerVerificationRevision::factory()->create(['policy_reference' => 'P2-KYC-2026-10-28-v1']);
    $service = app(ConsumerIdentityService::class);
    $service->applyProviderDecision(kycPolicyObservation($revision));
    $examiner = kycPolicyReviewer();
    app(ConsumerIdentityService::class)->recommendApproval($examiner, $revision->reference, 1, 'checks_reviewed', 'review:examiner', [], 'standard', now()->addYear()->toDateString());
    if ($policyChanges) {
        config(['pull2spend.consumer.policy_reference' => 'changed-policy']);
    } else {
        $this->travel(1)->seconds();
        $service->applyProviderDecision(kycPolicyObservation($revision));
    }
    $checker = kycPolicyReviewer();
    expect(fn () => app(ConsumerIdentityService::class)->review($checker, $revision->reference, 1, KycState::Verified, 'checks_reviewed', 'review:checker', recommendationReference: $revision->latestRecommendation()->value('reference')))
        ->toThrow(ConsumerIdentityFailure::class, $policyChanges ? 'identity_policy_changed' : 'examiner_recommendation_stale');
    $this->assertDatabaseCount('consumer_enrollment_intents', 0);
})->with([false, true]);

it('blocks approval at the screening freshness boundary', function (): void {
    $this->freezeTime();
    $revision = ConsumerVerificationRevision::factory()->create(['policy_reference' => 'P2-KYC-2026-10-28-v1']);
    $service = app(ConsumerIdentityService::class);
    $service->applyProviderDecision(kycPolicyObservation($revision));
    $examiner = kycPolicyReviewer();
    app(ConsumerIdentityService::class)->recommendApproval($examiner, $revision->reference, 1, 'checks_reviewed', 'review:examiner', [], 'standard', now()->addYear()->toDateString());
    $this->travel(24)->hours();
    $checker = kycPolicyReviewer();
    expect(fn () => app(ConsumerIdentityService::class)->review($checker, $revision->reference, 1, KycState::Verified, 'checks_reviewed', 'review:checker', recommendationReference: $revision->latestRecommendation()->value('reference')))
        ->toThrow(ConsumerIdentityFailure::class, 'screening_stale');
    $this->assertDatabaseCount('consumer_enrollment_intents', 0);
});

it('rejects self examination and expired documents', function (bool $selfReview): void {
    $revision = ConsumerVerificationRevision::factory()->create(['policy_reference' => 'P2-KYC-2026-10-28-v1']);
    $service = app(ConsumerIdentityService::class);
    $service->applyProviderDecision(kycPolicyObservation($revision));
    $examiner = kycPolicyReviewer($selfReview ? $revision->identity->user : null);
    expect(fn () => app(ConsumerIdentityService::class)->recommendApproval($examiner, $revision->reference, 1, 'checks_reviewed', 'review:examiner', [], 'standard',
        $selfReview ? now()->addYear()->toDateString() : now()->subDay()->toDateString()))
        ->toThrow(ConsumerIdentityFailure::class, $selfReview ? 'self_identity_review_denied' : 'identity_document_expired');
    $this->assertDatabaseCount('consumer_review_decisions', 0);
})->with([false, true]);

it('refreshes clear monitoring evidence without extending KYC and revokes eligibility on pending evidence', function (): void {
    $this->freezeTime();
    $revision = ConsumerVerificationRevision::factory()->create(['policy_reference' => 'P2-KYC-2026-10-28-v1']);
    $service = app(ConsumerIdentityService::class);
    $service->applyProviderDecision(kycPolicyObservation($revision));
    $examiner = kycPolicyReviewer();
    app(ConsumerIdentityService::class)->recommendApproval($examiner, $revision->reference, 1, 'checks_reviewed', 'review:examiner', [], 'standard', now()->addYears(2)->toDateString());
    $checker = kycPolicyReviewer();
    app(ConsumerIdentityService::class)->review($checker, $revision->reference, 1, KycState::Verified, 'checks_reviewed', 'review:checker', recommendationReference: $revision->latestRecommendation()->value('reference'));
    $expiry = $revision->identity->fresh()->expires_at;
    $this->travel(12)->hours();
    $service->applyProviderDecision(kycPolicyObservation($revision));
    expect($revision->fresh()->provider_observed_at->toDateTimeString() === now()->toDateTimeString())->toBeTrue();
    expect($revision->identity->fresh()->expires_at->equalTo($expiry))->toBeTrue();
    $this->travel(1)->seconds();
    $service->applyProviderDecision(kycPolicyObservation($revision, ['sanctions' => 'pending']));
    expect($revision->identity->fresh()->kyc_state)->toBe(KycState::ReverificationRequired);
    expect($revision->identity->fresh()->expires_at)->toBeNull();
    $this->assertDatabaseCount('consumer_enrollment_intents', 1);
});

it('rejects a replaced recommendation even when its observations and resolved checks are unchanged', function (): void {
    $this->freezeTime();
    $revision = ConsumerVerificationRevision::factory()->create(['policy_reference' => 'P2-KYC-2026-10-28-v1']);
    app(ConsumerIdentityService::class)->applyProviderDecision(kycPolicyObservation($revision));
    $examiner = kycPolicyReviewer();
    app(ConsumerIdentityService::class)->recommendApproval($examiner, $revision->reference, 1, 'checks_reviewed', 'review:original', [], 'elevated', now()->addYears(2)->toDateString());
    $reviewedReference = $revision->latestRecommendation()->value('reference');
    app(ConsumerIdentityService::class)->recommendApproval($examiner, $revision->reference, 1, 'risk_reassessed', 'review:replacement', [], 'standard', now()->addYears(2)->toDateString());
    $checker = kycPolicyReviewer();
    expect(fn () => app(ConsumerIdentityService::class)->review($checker, $revision->reference, 1, KycState::Verified, 'checks_reviewed', 'review:checker',
        recommendationReference: $reviewedReference))->toThrow(ConsumerIdentityFailure::class, 'examiner_recommendation_stale');
    $this->assertDatabaseCount('consumer_enrollment_intents', 0);
    expect($revision->identity->fresh()->kyc_state)->toBe(KycState::UnderReview);
});

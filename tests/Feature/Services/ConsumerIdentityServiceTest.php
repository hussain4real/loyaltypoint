<?php

declare(strict_types=1);

use App\Enums\AccountRestriction;
use App\Enums\KycState;
use App\Enums\StaffRole;
use App\Models\ConsumerEnrollmentIntent;
use App\Models\ConsumerIdentity;
use App\Models\ConsumerReviewDecision;
use App\Models\ConsumerSession;
use App\Models\ConsumerVerificationReceipt;
use App\Models\ConsumerVerificationRevision;
use App\Models\OperationalCase;
use App\Models\StaffMembership;
use App\Models\User;
use App\Services\AuditService;
use App\Services\ConsumerIdentityFailure;
use App\Services\ConsumerIdentityService;
use App\Services\ConsumerVerificationDecision;
use App\Services\ConsumerVerificationGateway;
use App\Services\LocalConsumerVerificationGateway;
use App\Services\UnavailableConsumerVerificationGateway;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['pull2spend.consumer.enabled' => true, 'pull2spend.consumer.verification_mode' => 'fixture',
        'pull2spend.consumer.verification_key' => str_repeat('local-fixture', 4), 'pull2spend.consumer.policy_reference' => 'fixture-policy-v1',
        'pull2spend.consumer.verification_validity_seconds' => 3600, 'pull2spend.consumer.policy_approved' => false,
        'pull2spend.consumer.idle_lock_seconds' => 7200]);
    app()->bind(ConsumerVerificationGateway::class, LocalConsumerVerificationGateway::class);
});

function consumerIdentityEvidence(ConsumerSession $session, string $suffix = ''): array
{
    $identity = ConsumerIdentity::findOrFail($session->consumer_identity_id);

    return ['identity_document_reference' => 'fixture:'.$identity->reference.'/document'.$suffix,
        'liveness_evidence_reference' => 'fixture:'.$identity->reference.'/liveness'.$suffix,
        'identity_details_reference' => 'fixture:'.$identity->reference.'/details'.$suffix];
}

function consumerIdentityDecision(ConsumerVerificationRevision $revision, array $changes = [], ?string $eventReference = null): ConsumerVerificationDecision
{
    return new ConsumerVerificationDecision($revision->source, ['event_reference' => $eventReference ?? (string) Str::uuid(),
        'revision_reference' => $revision->reference, 'operation_reference' => $revision->operation_reference,
        'checks' => array_replace(array_fill_keys(ConsumerVerificationDecision::CHECKS, 'clear'), $changes),
        'occurred_at' => now()->toIso8601String(), 'evidence_reference' => 'fixture:provider-evidence']);
}

function consumerIdentityReviewer(StaffRole $role = StaffRole::Compliance, ?array $customers = null): User
{
    config(['pull2spend.backoffice_enabled' => true]);
    $staff = StaffMembership::factory()->enrolled()->create(['role' => $role, 'customer_ids' => $customers, 'partner_ids' => null]);
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

/** @param list<string> $resolvedChecks */
function consumerIdentityPrepareChecker(ConsumerVerificationRevision $revision, array $resolvedChecks = []): User
{
    $examiner = consumerIdentityReviewer();
    app(ConsumerIdentityService::class)->recommendApproval($examiner, $revision->reference, $revision->revision,
        'checks_examined', 'review:examiner', $resolvedChecks, 'standard', now()->addYears(2)->toDateString());

    return consumerIdentityReviewer();
}

function consumerIdentityApprove(ConsumerVerificationRevision $revision): void
{
    $checker = consumerIdentityPrepareChecker($revision);
    app(ConsumerIdentityService::class)->review($checker, $revision->reference, $revision->revision,
        KycState::Verified, 'checks_approved', 'review:checker');
}

it('keeps the provider unavailable by default without a fabricated enrollment or upload', function (): void {
    app()->bind(ConsumerVerificationGateway::class, UnavailableConsumerVerificationGateway::class);
    $session = ConsumerSession::factory()->create();

    expect(fn () => app(ConsumerIdentityService::class)->submit($session, consumerIdentityEvidence($session), 'operation-123'))
        ->toThrow(ConsumerIdentityFailure::class, 'verification_provider_unavailable');
    $this->assertDatabaseCount('consumer_verification_revisions', 0);
    $this->assertDatabaseCount('consumer_enrollment_intents', 0);
});

it('submits only owned protected references with stable idempotency and preserved encrypted evidence', function (): void {
    $session = ConsumerSession::factory()->create();
    $service = app(ConsumerIdentityService::class);
    $evidence = consumerIdentityEvidence($session);
    $revision = $service->submit($session, $evidence, 'operation-123');
    $repeated = $service->submit($session, $evidence, 'operation-123');

    expect($repeated->id)->toBe($revision->id);
    expect($revision)->state->toBe(KycState::Pending)->evidence->toBe($evidence);
    expect(DB::table('consumer_verification_revisions')->value('evidence'))->not->toContain($evidence['identity_document_reference']);
    expect(fn () => $service->submit($session, consumerIdentityEvidence($session, '-changed'), 'operation-123'))->toThrow(ConsumerIdentityFailure::class, 'idempotency_conflict');
    $this->assertDatabaseCount('consumer_verification_revisions', 1);
});

it('rejects cross-consumer evidence and client decision fields before creating any revision', function (bool $extra): void {
    $session = ConsumerSession::factory()->create();
    $other = ConsumerSession::factory()->create();
    $evidence = $extra ? [...consumerIdentityEvidence($session), 'verified' => true] : consumerIdentityEvidence($other);

    expect(fn () => app(ConsumerIdentityService::class)->submit($session, $evidence, 'operation-123'))
        ->toThrow(ConsumerIdentityFailure::class, $extra ? 'invalid_identity_evidence' : 'identity_evidence_not_owned');
    $this->assertDatabaseCount('consumer_verification_revisions', 0);
})->with([false, true]);

it('emits exactly one initial enrollment intent across repeated and distinct approval callbacks', function (): void {
    $this->freezeTime();
    $session = ConsumerSession::factory()->create();
    $service = app(ConsumerIdentityService::class);
    $revision = $service->submit($session, consumerIdentityEvidence($session), 'operation-123');
    $decision = consumerIdentityDecision($revision);
    $first = $service->applyProviderDecision($decision);
    $duplicate = $service->applyProviderDecision($decision);
    $service->applyProviderDecision(consumerIdentityDecision($revision));
    consumerIdentityApprove($revision);

    expect($duplicate->id)->toBe($first->id);
    expect(ConsumerIdentity::find($session->consumer_identity_id)->kyc_state)->toBe(KycState::Verified);
    $this->assertDatabaseCount('consumer_verification_receipts', 2);
    $this->assertDatabaseCount('consumer_enrollment_intents', 1);
    expect(ConsumerEnrollmentIntent::first()->payload)->toMatchArray(['wallet_intent_reference' => 'wallet:'.$session->identity->reference, 'card_intent_reference' => 'card:'.$session->identity->reference]);
    $this->assertDatabaseCount('foundation_deliveries', 2);
});

it('keeps every unresolved check under review rather than accepting a successful document result', function (string $check): void {
    $session = ConsumerSession::factory()->create();
    $service = app(ConsumerIdentityService::class);
    $revision = $service->submit($session, consumerIdentityEvidence($session), 'operation-123');
    $service->applyProviderDecision(consumerIdentityDecision($revision, [$check => 'hit']));

    expect($session->identity->fresh()->kyc_state)->toBe(KycState::UnderReview);
    $this->assertDatabaseCount('consumer_enrollment_intents', 0);
    $this->assertDatabaseHas('operational_cases', ['category' => 'kyc', 'source_reference' => $revision->reference, 'customer_id' => $session->user_id]);
})->with(ConsumerVerificationDecision::CHECKS);

it('does not promote pending or rejected provider checks to approved', function (string $checkState, KycState $expected): void {
    $session = ConsumerSession::factory()->create();
    $service = app(ConsumerIdentityService::class);
    $revision = $service->submit($session, consumerIdentityEvidence($session), 'operation-123');
    $service->applyProviderDecision(consumerIdentityDecision($revision, ['liveness' => $checkState]));

    expect($session->identity->fresh()->kyc_state)->toBe($expected);
    $this->assertDatabaseCount('consumer_enrollment_intents', 0);
})->with([['pending', KycState::Pending], ['rejected', KycState::Rejected]]);

it('retains a prior receipt and rejects changed content under its source event identity', function (): void {
    $session = ConsumerSession::factory()->create();
    $service = app(ConsumerIdentityService::class);
    $revision = $service->submit($session, consumerIdentityEvidence($session), 'operation-123');
    $service->applyProviderDecision(consumerIdentityDecision($revision, ['sanctions' => 'hit'], 'event-1'));

    expect(fn () => $service->applyProviderDecision(consumerIdentityDecision($revision, [], 'event-1')))->toThrow(ConsumerIdentityFailure::class, 'idempotency_conflict');
    $this->assertDatabaseCount('consumer_verification_receipts', 1);
    expect($session->identity->fresh()->kyc_state)->toBe(KycState::UnderReview);
});

it('requires authenticated source operation correlation before applying provider evidence', function (string $changed): void {
    $revision = ConsumerVerificationRevision::factory()->create();
    $payload = consumerIdentityDecision($revision)->payload;
    $source = $changed === 'source' ? 'other-source' : $revision->source;
    if ($changed === 'operation') {
        $payload['operation_reference'] = 'operation:another-consumer';
    }

    expect(fn () => app(ConsumerIdentityService::class)->applyProviderDecision(new ConsumerVerificationDecision($source, $payload)))
        ->toThrow(ConsumerIdentityFailure::class, 'verification_subject_not_found');
    $this->assertDatabaseCount('consumer_verification_receipts', 0);
})->with(['source', 'operation']);

it('requires explicit check resolution evidence before a compliance approval', function (): void {
    $session = ConsumerSession::factory()->create();
    $service = app(ConsumerIdentityService::class);
    $revision = $service->submit($session, consumerIdentityEvidence($session), 'operation-123');
    $service->applyProviderDecision(consumerIdentityDecision($revision, ['sanctions' => 'hit', 'pep' => 'hit']));
    $reviewer = consumerIdentityReviewer();
    $service = app(ConsumerIdentityService::class);

    expect(fn () => $service->review($reviewer, $revision->reference, 1, KycState::Verified, 'checks_resolved', 'review:evidence', ['sanctions']))
        ->toThrow(ConsumerIdentityFailure::class, 'screening_review_unresolved');
    $reviewer = consumerIdentityPrepareChecker($revision, ['sanctions', 'pep']);
    $service = app(ConsumerIdentityService::class);
    $service->review($reviewer, $revision->reference, 1, KycState::Verified, 'checks_resolved', 'review:evidence', ['sanctions', 'pep']);

    expect($session->identity->fresh()->kyc_state)->toBe(KycState::Verified);
    $this->assertDatabaseCount('consumer_review_decisions', 2);
    $this->assertDatabaseCount('consumer_enrollment_intents', 1);
});

it('never lets a stale clear callback override a manual rejection', function (): void {
    $session = ConsumerSession::factory()->create();
    $service = app(ConsumerIdentityService::class);
    $revision = $service->submit($session, consumerIdentityEvidence($session), 'operation-123');
    $service->applyProviderDecision(consumerIdentityDecision($revision, ['adverse_media' => 'hit']));
    $reviewer = consumerIdentityReviewer();
    $service = app(ConsumerIdentityService::class);
    $service->review($reviewer, $revision->reference, 1, KycState::Rejected, 'review_rejected', 'review:evidence');
    $service->applyProviderDecision(consumerIdentityDecision($revision));

    expect($session->identity->fresh()->kyc_state)->toBe(KycState::Rejected);
    $this->assertDatabaseCount('consumer_enrollment_intents', 0);
    $this->assertDatabaseCount('consumer_verification_receipts', 2);
});

it('denies KYC review to generic case managers and foreign customer scopes', function (StaffRole $role, bool $foreign): void {
    $revision = ConsumerVerificationRevision::factory()->create();
    $reviewer = consumerIdentityReviewer($role, $foreign ? [$revision->customer_id + 1] : null);

    expect(fn () => app(ConsumerIdentityService::class)->review($reviewer, $revision->reference, 1, KycState::Rejected, 'review_rejected', 'review:evidence'))
        ->toThrow($foreign ? ModelNotFoundException::class : AuthorizationException::class);
    $this->assertDatabaseCount('consumer_review_decisions', 0);
})->with([[StaffRole::Support, false], [StaffRole::Finance, false], [StaffRole::PartnerViewer, false], [StaffRole::Compliance, true]]);

it('retains one initial intent through reviewed reverification and ignores old revision callbacks', function (): void {
    $session = ConsumerSession::factory()->create();
    $service = app(ConsumerIdentityService::class);
    $first = $service->submit($session, consumerIdentityEvidence($session), 'operation-123');
    $service->applyProviderDecision(consumerIdentityDecision($first));
    consumerIdentityApprove($first);
    $reviewer = consumerIdentityReviewer();
    $service = app(ConsumerIdentityService::class);
    $service->review($reviewer, $first->reference, 1, KycState::ReverificationRequired, 'policy_reverification', 'review:evidence');
    $second = $service->submit($session, consumerIdentityEvidence($session, '-new'), 'operation-456', $first->reference);
    $service->applyProviderDecision(consumerIdentityDecision($first));
    expect($session->identity->fresh()->kyc_state)->toBe(KycState::Pending);
    $service->applyProviderDecision(consumerIdentityDecision($second));
    expect($session->identity->fresh()->kyc_state)->toBe(KycState::UnderReview);
    $reviewer = consumerIdentityPrepareChecker($second);
    $service = app(ConsumerIdentityService::class);
    $service->review($reviewer, $second->reference, 2, KycState::Verified, 'checks_resolved', 'review:new-evidence');

    expect($session->identity->fresh()->kyc_state)->toBe(KycState::Verified);
    $this->assertDatabaseCount('consumer_enrollment_intents', 1);
});

it('keeps suspension independent from KYC and delays the initial intent until authorized clearance', function (): void {
    $session = ConsumerSession::factory()->create();
    $session->identity->update(['restriction' => AccountRestriction::Suspended]);
    $service = app(ConsumerIdentityService::class);
    $revision = $service->submit($session, consumerIdentityEvidence($session), 'operation-123');
    $service->applyProviderDecision(consumerIdentityDecision($revision));
    consumerIdentityApprove($revision);
    expect($session->identity->fresh())->kyc_state->toBe(KycState::Verified)->restriction->toBe(AccountRestriction::Suspended);
    $this->assertDatabaseCount('consumer_enrollment_intents', 0);
    expect(fn () => $service->assertEligible($session, 'card.issue'))->toThrow(ConsumerIdentityFailure::class, 'account_restricted');
    $reviewer = consumerIdentityReviewer();
    app(ConsumerIdentityService::class)->restrict($reviewer, $session->identity->reference, AccountRestriction::None, 'restriction_cleared', 'review:restriction');

    $this->assertDatabaseCount('consumer_enrollment_intents', 1);
    expect($session->identity->fresh()->restriction)->toBe(AccountRestriction::None);
});

it('does not extend verification validity through repeated clear callbacks', function (): void {
    $this->freezeTime();
    $session = ConsumerSession::factory()->create();
    $service = app(ConsumerIdentityService::class);
    $revision = $service->submit($session, consumerIdentityEvidence($session), 'operation-123');
    $service->applyProviderDecision(consumerIdentityDecision($revision));
    consumerIdentityApprove($revision);
    $expires = $session->identity->fresh()->expires_at;
    $this->travel(120)->seconds();
    $service->applyProviderDecision(consumerIdentityDecision($revision));

    expect($session->identity->fresh()->expires_at->equalTo($expires))->toBeTrue();
    $this->assertDatabaseCount('consumer_enrollment_intents', 1);
});

it('keeps local fixture approval distinct from approved real-provider financial eligibility', function (): void {
    $session = ConsumerSession::factory()->create();
    $service = app(ConsumerIdentityService::class);
    $revision = $service->submit($session, consumerIdentityEvidence($session), 'operation-123');
    $service->applyProviderDecision(consumerIdentityDecision($revision));
    consumerIdentityApprove($revision);
    $eligibility = $service->eligibility($session);

    expect($eligibility['allowed_actions'])->toBe(['onboarding', 'support']);
    expect($eligibility['reason_codes'])->toContain('identity_policy_unapproved', 'verification_provider_not_selected');
    expect(fn () => $service->assertEligible($session, 'card.issue'))->toThrow(ConsumerIdentityFailure::class, 'identity_policy_unapproved');
});

it('keeps provider receipt review and initial intent immutable even through direct SQL', function (string $table): void {
    $record = match ($table) {
        'consumer_verification_receipts' => ConsumerVerificationReceipt::factory()->create(),
        'consumer_review_decisions' => ConsumerReviewDecision::factory()->create(),
        'consumer_enrollment_intents' => ConsumerEnrollmentIntent::factory()->create(),
    };

    expect(fn () => $record->delete())->toThrow(LogicException::class);
    expect(fn () => DB::table($table)->where('id', $record->id)->delete())->toThrow(QueryException::class);
})->with(['consumer_verification_receipts', 'consumer_review_decisions', 'consumer_enrollment_intents']);

it('rolls back receipt approval intent and notification together on audit failure', function (): void {
    $session = ConsumerSession::factory()->create();
    $service = app(ConsumerIdentityService::class);
    $revision = $service->submit($session, consumerIdentityEvidence($session), 'operation-123');
    $this->mock(AuditService::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('audit unavailable'));

    expect(fn () => app(ConsumerIdentityService::class)->applyProviderDecision(consumerIdentityDecision($revision)))->toThrow(RuntimeException::class, 'audit unavailable');
    expect($session->identity->fresh()->kyc_state)->toBe(KycState::Pending);
    $this->assertDatabaseCount('consumer_enrollment_intents', 0);
    $this->assertDatabaseCount('consumer_verification_receipts', 0);
    $this->assertDatabaseCount('foundation_deliveries', 0);
});

it('latches an unresolved hit through a later clear callback until an authorized explicit disposition', function (): void {
    $this->freezeTime();
    $session = ConsumerSession::factory()->create();
    $service = app(ConsumerIdentityService::class);
    $revision = $service->submit($session, consumerIdentityEvidence($session), 'operation-123');
    $service->applyProviderDecision(consumerIdentityDecision($revision, ['sanctions' => 'hit']));
    $this->travel(1)->seconds();
    $service->applyProviderDecision(consumerIdentityDecision($revision));

    expect($session->identity->fresh()->kyc_state)->toBe(KycState::UnderReview);
    expect($revision->fresh()->unresolved_checks)->toBe(['sanctions']);
    $this->assertDatabaseCount('consumer_enrollment_intents', 0);
    $reviewer = consumerIdentityReviewer();
    $service = app(ConsumerIdentityService::class);
    expect(fn () => $service->review($reviewer, $revision->reference, 1, KycState::Verified, 'checks_resolved', 'review:evidence'))
        ->toThrow(ConsumerIdentityFailure::class, 'screening_review_unresolved');
    $reviewer = consumerIdentityPrepareChecker($revision, ['sanctions']);
    $service = app(ConsumerIdentityService::class);
    $service->review($reviewer, $revision->reference, 1, KycState::Verified, 'checks_resolved', 'review:evidence', ['sanctions']);
    $this->assertDatabaseCount('consumer_enrollment_intents', 1);
    expect(ConsumerEnrollmentIntent::first()->payload)->toMatchArray(['verification_assurance' => 'local_fixture', 'financial_authority' => false, 'requires_downstream_eligibility' => true, 'policy_approved_at_emission' => false]);
});

it('retains conservative checks and an owned case for conflicting evidence at the same instant', function (): void {
    $this->freezeTime();
    $session = ConsumerSession::factory()->create();
    $service = app(ConsumerIdentityService::class);
    $revision = $service->submit($session, consumerIdentityEvidence($session), 'operation-123');
    $service->applyProviderDecision(consumerIdentityDecision($revision, ['pep' => 'hit']));
    $service->applyProviderDecision(consumerIdentityDecision($revision));

    expect($session->identity->fresh()->kyc_state)->toBe(KycState::UnderReview);
    expect($revision->fresh()->checks['pep'])->toBe('hit');
    $this->assertDatabaseHas('operational_cases', ['category' => 'kyc', 'source_reference' => $revision->reference]);
    $this->assertDatabaseHas('audit_events', ['reason' => 'conflicting_evidence']);
    $this->assertDatabaseCount('consumer_enrollment_intents', 0);
});

it('does not promote fixture evidence to live eligibility after a configuration switch', function (): void {
    $session = ConsumerSession::factory()->create();
    $service = app(ConsumerIdentityService::class);
    $revision = $service->submit($session, consumerIdentityEvidence($session), 'operation-123');
    $service->applyProviderDecision(consumerIdentityDecision($revision));
    consumerIdentityApprove($revision);
    config(['pull2spend.consumer.verification_mode' => 'selected-provider', 'pull2spend.consumer.policy_approved' => true]);
    $provider = $this->mock(ConsumerVerificationGateway::class);
    $provider->shouldReceive('source')->andReturn('selected-provider');
    $provider->shouldReceive('isAvailable')->andReturnTrue();

    expect(app(ConsumerIdentityService::class)->eligibility($session)['reason_codes'])->toContain('verification_provider_not_selected');
    expect(fn () => app(ConsumerIdentityService::class)->assertEligible($session, 'wallet.read'))
        ->toThrow(ConsumerIdentityFailure::class, 'verification_provider_not_selected');
});

it('requires current approved evidence policy expiry and action-bound step-up at every financial entry', function (): void {
    $this->freezeTime();
    $session = ConsumerSession::factory()->create();
    $revision = ConsumerVerificationRevision::factory()->create(['consumer_identity_id' => $session->consumer_identity_id,
        'customer_id' => $session->user_id, 'source' => 'selected-provider', 'state' => KycState::Verified, 'provider_observed_at' => now()]);
    $session->identity->update(['verified_at' => now(), 'expires_at' => now()->addHour(), 'policy_reference' => 'fixture-policy-v1']);
    config(['pull2spend.consumer.verification_mode' => 'selected-provider', 'pull2spend.consumer.policy_approved' => true]);
    $provider = $this->mock(ConsumerVerificationGateway::class);
    $provider->shouldReceive('source')->andReturn('selected-provider');
    $provider->shouldReceive('isAvailable')->andReturnTrue();
    $service = app(ConsumerIdentityService::class);

    expect($service->eligibility($session)['allowed_actions'])->toContain('wallet.read', 'card.issue', 'redemption.confirm');
    expect(fn () => $service->assertEligible($session, 'card.issue'))->toThrow(ConsumerIdentityFailure::class, 'step_up_required');
    config(['pull2spend.consumer.step_up_actions' => []]);
    $service->assertEligible($session, 'wallet.read');
    $session->identity->update(['expires_at' => now()]);
    expect(fn () => $service->assertEligible($session, 'wallet.read'))->toThrow(ConsumerIdentityFailure::class, 'kyc_required');
});

it('carries required document exception review across remediation instead of silently clearing the earlier case', function (): void {
    $session = ConsumerSession::factory()->create();
    $service = app(ConsumerIdentityService::class);
    $first = $service->submit($session, consumerIdentityEvidence($session), 'operation-123');
    $service->applyProviderDecision(consumerIdentityDecision($first, ['document' => 'rejected']));
    $second = $service->submit($session, consumerIdentityEvidence($session, '-remediation'), 'operation-456', $first->reference);
    $service->applyProviderDecision(consumerIdentityDecision($second));

    expect($session->identity->fresh()->kyc_state)->toBe(KycState::UnderReview);
    expect($second->fresh()->unresolved_checks)->toBe(['document']);
    $this->assertDatabaseCount('consumer_enrollment_intents', 0);
    $reviewer = consumerIdentityReviewer();
    $service = app(ConsumerIdentityService::class);
    expect(fn () => $service->review($reviewer, $second->reference, 2, KycState::Verified, 'remediation_reviewed', 'review:new-document'))
        ->toThrow(ConsumerIdentityFailure::class, 'screening_review_unresolved');
    $reviewer = consumerIdentityPrepareChecker($second, ['document']);
    $service = app(ConsumerIdentityService::class);
    $service->review($reviewer, $second->reference, 2, KycState::Verified, 'remediation_reviewed', 'review:new-document', ['document']);
    $this->assertDatabaseCount('consumer_enrollment_intents', 1);
});

it('requires a new reviewed revision for fresh adverse evidence after manual approval even when the previous case is closed', function (string $check, string $outcome, bool $sameInstant): void {
    $this->freezeTime();
    $session = ConsumerSession::factory()->create();
    $service = app(ConsumerIdentityService::class);
    $first = $service->submit($session, consumerIdentityEvidence($session), 'operation-123');
    $originalTime = now()->toIso8601String();
    $service->applyProviderDecision(consumerIdentityDecision($first, ['sanctions' => 'hit']));
    $reviewer = consumerIdentityReviewer();
    $service = app(ConsumerIdentityService::class);
    $reviewer = consumerIdentityPrepareChecker($first, ['sanctions']);
    $service = app(ConsumerIdentityService::class);
    $service->review($reviewer, $first->reference, 1, KycState::Verified, 'checks_resolved', 'review:original', ['sanctions']);
    OperationalCase::where('source_reference', $first->reference)->update(['state' => 'closed', 'closed_at' => now()]);
    if (! $sameInstant) {
        $this->travel(1)->seconds();
    }
    $receipt = $service->applyProviderDecision(consumerIdentityDecision($first, [$check => $outcome]));

    expect($session->identity->fresh()->kyc_state)->toBe(KycState::ReverificationRequired);
    expect($session->identity->fresh()->expires_at)->toBeNull();
    expect($first->fresh()->unresolved_checks)->toContain($check);
    expect(ConsumerReviewDecision::where('decision', 'verified')->sole()->decision)->toBe('verified');
    $this->assertDatabaseCount('consumer_enrollment_intents', 1);
    $this->assertDatabaseHas('operational_cases', ['source_reference' => 'kyc-incident:'.$receipt->reference, 'state' => 'open', 'customer_id' => $session->user_id]);
    $stale = consumerIdentityDecision($first)->payload;
    $stale['occurred_at'] = $originalTime;
    $service->applyProviderDecision(new ConsumerVerificationDecision($first->source, $stale));
    expect($session->identity->fresh()->kyc_state)->toBe(KycState::ReverificationRequired);
    expect(fn () => $service->review($reviewer, $first->reference, 1, KycState::Verified, 'checks_resolved', 'review:old-replay', [$check]))
        ->toThrow(ConsumerIdentityFailure::class, 'identity_review_conflict');
    $next = $service->submit($session, consumerIdentityEvidence($session, '-fresh-review'), 'operation-456', $first->reference);
    $service->applyProviderDecision(consumerIdentityDecision($next));
    expect($session->identity->fresh()->kyc_state)->toBe(KycState::UnderReview);
    $reviewer = consumerIdentityPrepareChecker($next, [$check]);
    $service = app(ConsumerIdentityService::class);
    $service->review($reviewer, $next->reference, 2, KycState::Verified, 'fresh_checks_resolved', 'review:fresh-evidence', [$check]);
    expect($session->identity->fresh()->kyc_state)->toBe(KycState::Verified);
    $this->assertDatabaseCount('consumer_enrollment_intents', 1);
    $this->assertDatabaseCount('consumer_review_decisions', 4);
})->with([['pep', 'hit', false], ['document', 'rejected', false], ['pep', 'hit', true]]);

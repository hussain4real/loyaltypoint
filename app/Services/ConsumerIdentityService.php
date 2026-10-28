<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AccountRestriction;
use App\Enums\KycState;
use App\Models\ConsumerEnrollmentIntent;
use App\Models\ConsumerIdentity;
use App\Models\ConsumerReviewDecision;
use App\Models\ConsumerSession;
use App\Models\ConsumerVerificationReceipt;
use App\Models\ConsumerVerificationRevision;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ConsumerIdentityService
{
    public const FINANCIAL_ACTIONS = ['redemption.confirm', 'wallet.read', 'card.issue', 'card.reveal', 'card.freeze', 'card.unfreeze', 'card.close', 'card.reissue', 'card.provision'];

    public const EVIDENCE_FIELDS = ['identity_document_reference', 'liveness_evidence_reference', 'identity_details_reference'];

    public function __construct(
        private ConsumerVerificationGateway $gateway,
        private ConsumerSecurityService $security,
        private StaffAccessService $access,
        private AuditService $audit,
        private CaseService $cases,
        private FoundationNotifications $notifications,
    ) {}

    /** @return array<string, mixed> */
    public function eligibility(ConsumerSession $session): array
    {
        $this->security->assertSession($session);
        $identity = $this->identityFor($session);
        $reasons = $this->reasons($identity);
        $actions = ['onboarding', 'support'];
        if ($reasons === []) {
            $actions = array_merge($actions, self::FINANCIAL_ACTIONS);
        }
        if ($identity->restriction === AccountRestriction::None && ($reasons === [] || config('pull2spend.consumer.pre_kyc_read_enabled', false))) {
            $actions[] = 'aggregation';
        }

        return ['reference' => $identity->reference, 'kyc_state' => $identity->kyc_state->value,
            'account_restriction' => $identity->restriction->value, 'allowed_actions' => $actions,
            'reason_codes' => $reasons, 'verification_available' => $this->gateway->isAvailable(),
            'policy_reference' => $identity->policy_reference, 'policy_approved' => (bool) config('pull2spend.consumer.policy_approved', false),
            'next_action' => $reasons === [] ? 'continue' : 'review_identity_status'];
    }

    public function assertEligible(ConsumerSession $session, string $action, ?string $actionDigest = null): void
    {
        $this->security->assertSession($session);
        DB::transaction(function () use ($session, $action, $actionDigest): void {
            $identity = $this->identityFor($session, true);
            if (in_array($action, ['onboarding', 'support'], true)) {
                return;
            }
            if ($action === 'aggregation' && config('pull2spend.consumer.pre_kyc_read_enabled', false) && $identity->restriction === AccountRestriction::None) {
                return;
            }
            if (! in_array($action, [...self::FINANCIAL_ACTIONS, 'aggregation'], true)) {
                throw new ConsumerIdentityFailure('unsupported_consumer_action');
            }
            $reasons = $this->reasons($identity);
            if ($reasons !== []) {
                throw new ConsumerIdentityFailure($reasons[0]);
            }
            if (in_array($action, config('pull2spend.consumer.step_up_actions', self::FINANCIAL_ACTIONS), true)
                && ($actionDigest === null || ! $this->security->hasStepUp($session, $actionDigest))) {
                throw new ConsumerIdentityFailure('step_up_required');
            }
        }, attempts: 5);
    }

    /** @param array<string, mixed> $evidence */
    public function submit(ConsumerSession $session, array $evidence, string $key, ?string $remediationReference = null): ConsumerVerificationRevision
    {
        $this->security->assertSession($session);
        $identity = $this->identityFor($session);
        if (! $this->gateway->isAvailable()) {
            throw new ConsumerIdentityFailure('verification_provider_unavailable', 503);
        }
        $policy = config('pull2spend.consumer.policy_reference');
        if (! is_string($policy) || trim($policy) === '') {
            throw new ConsumerIdentityFailure('identity_policy_required', 503);
        }
        AuditService::assertReference($policy);
        if (array_diff(array_keys($evidence), self::EVIDENCE_FIELDS) !== [] || array_diff(self::EVIDENCE_FIELDS, array_keys($evidence)) !== []) {
            throw new ConsumerIdentityFailure('invalid_identity_evidence', 422);
        }
        foreach ($evidence as $reference) {
            if (! is_string($reference) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:\/-]{0,179}\z/', $reference) !== 1) {
                throw new ConsumerIdentityFailure('invalid_identity_evidence', 422);
            }
        }
        $this->gateway->validateEvidence($identity->reference, $evidence);
        $keyHash = FoundationIdempotency::key($session->user_id, 'identity.enrollment', $key);
        $requestHash = FoundationIdempotency::fingerprint(['evidence' => $evidence, 'remediation_reference' => $remediationReference]);
        $revision = DB::transaction(function () use ($session, $evidence, $keyHash, $requestHash, $policy, $remediationReference): ConsumerVerificationRevision {
            $identity = $this->identityFor($session, true);
            $existing = ConsumerVerificationRevision::query()->where('idempotency_hash', $keyHash)->first();
            if ($existing !== null) {
                if (! hash_equals($existing->request_hash, $requestHash)) {
                    throw new ConsumerIdentityFailure('idempotency_conflict', 409);
                }

                return $existing;
            }
            if ($identity->restriction === AccountRestriction::Offboarded) {
                throw new ConsumerIdentityFailure('account_restricted');
            }
            $previous = ConsumerVerificationRevision::query()->where('consumer_identity_id', $identity->id)->where('revision', $identity->verification_revision)->first();
            if ($remediationReference !== null && ($previous === null || ! hash_equals($previous->reference, $remediationReference))) {
                throw new ConsumerIdentityFailure('enrollment_not_found', 404);
            }
            if (! in_array($identity->kyc_state, [KycState::NotStarted, KycState::Rejected, KycState::ReverificationRequired], true)
                && ! ($identity->kyc_state === KycState::Verified && $identity->expires_at !== null && $identity->expires_at <= now())) {
                throw new ConsumerIdentityFailure('enrollment_already_in_progress', 409);
            }
            $number = $identity->verification_revision + 1;
            $revision = ConsumerVerificationRevision::create([
                'consumer_identity_id' => $identity->id, 'customer_id' => $identity->user_id, 'revision' => $number,
                'policy_reference' => $policy, 'source' => $this->gateway->source(), 'idempotency_hash' => $keyHash,
                'request_hash' => $requestHash, 'evidence' => $evidence, 'unresolved_checks' => $previous?->unresolved_checks ?? [], 'state' => KycState::Pending,
                'manual_review_required' => $previous?->manual_decided_at !== null || $previous?->manual_review_required === true || in_array($identity->kyc_state, [KycState::Rejected, KycState::ReverificationRequired], true),
            ]);
            $identity->update(['verification_revision' => $number, 'kyc_state' => KycState::Pending, 'policy_reference' => $policy, 'verified_at' => null, 'expires_at' => null]);
            $this->audit->record($identity->user_id, 'identity.submitted', $revision->reference, 'verification_requested', customerId: $identity->user_id);

            return $revision;
        }, attempts: 5);
        if ($revision->operation_reference === null) {
            $result = $this->gateway->submit($identity->reference, $revision->reference, $evidence, $keyHash);
            DB::transaction(function () use ($revision, $result): void {
                $this->lockedIdentity($revision->consumer_identity_id);
                $current = ConsumerVerificationRevision::query()->whereKey($revision->id)->lockForUpdate()->firstOrFail();
                if ($current->operation_reference === null) {
                    $current->update(['operation_reference' => $result->operationReference]);
                } elseif (! hash_equals($current->operation_reference, $result->operationReference)) {
                    throw new ConsumerIdentityFailure('verification_operation_conflict', 409);
                }
            }, attempts: 5);
        }

        return $revision->fresh();
    }

    public function applyProviderDecision(ConsumerVerificationDecision $decision): ConsumerVerificationReceipt
    {
        $data = $decision->payload;
        $revision = ConsumerVerificationRevision::query()->where('reference', $data['revision_reference'])->first();
        if ($revision === null || ! hash_equals($revision->source, $decision->source)
            || ! is_string($revision->operation_reference) || ! hash_equals($revision->operation_reference, $data['operation_reference'])) {
            throw new ConsumerIdentityFailure('verification_subject_not_found', 404);
        }
        $digest = FoundationIdempotency::fingerprint($data);

        return DB::transaction(function () use ($revision, $decision, $data, $digest): ConsumerVerificationReceipt {
            $identity = $this->lockedIdentity($revision->consumer_identity_id);
            $current = ConsumerVerificationRevision::query()->whereKey($revision->id)->lockForUpdate()->firstOrFail();
            $receipt = ConsumerVerificationReceipt::query()->createOrFirst(['identity_hash' => hash('sha256', $decision->source.'|'.$data['event_reference'])], [
                'consumer_verification_revision_id' => $current->id, 'source' => $decision->source,
                'event_reference' => $data['event_reference'], 'payload_hash' => $digest, 'payload' => $data, 'occurred_at' => $decision->observedAt(),
            ]);
            if (! hash_equals($receipt->payload_hash, $digest)) {
                throw new ConsumerIdentityFailure('idempotency_conflict', 409);
            }
            if (! $receipt->wasRecentlyCreated) {
                return $receipt;
            }
            $adverseChecks = array_keys(array_filter($data['checks'], fn (string $check): bool => $check !== 'clear'));
            $newerEvidence = $current->provider_observed_at === null || $decision->observedAt() > $current->provider_observed_at;
            $changedAtSameInstant = $current->provider_observed_at !== null && $decision->observedAt() == $current->provider_observed_at
                && FoundationIdempotency::fingerprint($current->checks) !== FoundationIdempotency::fingerprint($data['checks']);
            if ($identity->verification_revision === $current->revision && $current->manual_decided_at !== null
                && in_array($current->state, [KycState::Verified, KycState::ReverificationRequired], true)
                && $adverseChecks !== [] && ($newerEvidence || $changedAtSameInstant)) {
                $current->update(['checks' => $data['checks'],
                    'unresolved_checks' => array_values(array_unique([...(array) $current->unresolved_checks, ...$adverseChecks])),
                    'manual_review_required' => true, 'provider_observed_at' => $decision->observedAt(), 'state' => KycState::ReverificationRequired]);
                $this->applyState($identity, $current, KycState::ReverificationRequired);
                $this->cases->open('kyc', 'kyc-incident:'.$receipt->reference, customerId: $identity->user_id);
                $this->audit->record(null, 'identity.adverse_evidence_received', $receipt->reference, 'new_adverse_evidence', customerId: $identity->user_id,
                    context: ['state' => KycState::ReverificationRequired->value]);

                return $receipt;
            }
            if ($identity->verification_revision === $current->revision && $current->manual_decided_at !== null
                && $current->state === KycState::Verified && $adverseChecks === [] && $newerEvidence
                && $current->unresolved_checks === []) {
                $current->update(['checks' => $data['checks'], 'provider_observed_at' => $decision->observedAt()]);
                $this->audit->record(null, 'identity.monitoring_refreshed', $receipt->reference, 'authenticated_evidence', customerId: $identity->user_id);

                return $receipt;
            }
            if ($identity->verification_revision !== $current->revision || $current->manual_decided_at !== null || $current->state === KycState::Rejected
                || ($current->provider_observed_at !== null && $decision->observedAt() < $current->provider_observed_at)) {
                $this->audit->record(null, 'identity.observation_ignored', $receipt->reference, 'superseded_evidence', customerId: $identity->user_id);

                return $receipt;
            }
            $checks = $data['checks'];
            $sameTimeConflict = $current->provider_observed_at !== null && $decision->observedAt() == $current->provider_observed_at
                && is_array($current->checks) && FoundationIdempotency::fingerprint($current->checks) !== FoundationIdempotency::fingerprint($checks);
            if ($sameTimeConflict) {
                $rank = ['clear' => 0, 'pending' => 1, 'hit' => 2, 'rejected' => 3];
                foreach ($checks as $name => $value) {
                    if ($rank[$current->checks[$name]] > $rank[$value]) {
                        $checks[$name] = $current->checks[$name];
                    }
                }
            }
            $unresolved = array_values(array_unique([...(array) $current->unresolved_checks, ...array_keys(array_filter($checks, fn (string $check): bool => in_array($check, ['hit', 'rejected'], true)))]));
            $requiresReview = $current->manual_review_required || $unresolved !== [] || $sameTimeConflict;
            $state = in_array('rejected', $checks, true) ? KycState::Rejected
                : (($requiresReview) ? KycState::UnderReview
                    : (in_array('pending', $checks, true) ? KycState::Pending : KycState::Verified));
            if ($state === KycState::Verified) {
                $state = KycState::UnderReview;
            }
            $current->update(['checks' => $checks, 'unresolved_checks' => $unresolved, 'manual_review_required' => $requiresReview, 'provider_observed_at' => $decision->observedAt(), 'state' => $state]);
            $this->applyState($identity, $current, $state);
            if (in_array($state, [KycState::UnderReview, KycState::Rejected], true)) {
                $this->cases->open('kyc', $current->reference, customerId: $identity->user_id);
            }
            $this->audit->record(null, 'identity.provider_observed', $receipt->reference, $sameTimeConflict ? 'conflicting_evidence' : 'authenticated_evidence', customerId: $identity->user_id, context: ['state' => $state->value]);

            return $receipt;
        }, attempts: 5);
    }

    /** @param list<string> $resolvedChecks */
    public function recommendApproval(User $actor, string $reference, int $expectedRevision, string $reason, string $evidenceReference, array $resolvedChecks, string $riskLevel, string $documentExpiresOn): ConsumerVerificationRevision
    {
        if (! in_array($riskLevel, ['standard', 'elevated'], true)
            || preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $documentExpiresOn) !== 1) {
            throw new ConsumerIdentityFailure('invalid_identity_review', 422);
        }
        try {
            $expiry = CarbonImmutable::createFromFormat('!Y-m-d', $documentExpiresOn, 'UTC');
        } catch (\Exception) {
            throw new ConsumerIdentityFailure('invalid_identity_review', 422);
        }
        if ($expiry === false || $expiry->format('Y-m-d') !== $documentExpiresOn || $expiry->endOfDay() <= now()) {
            throw new ConsumerIdentityFailure('identity_document_expired', 422);
        }

        return $this->recordReview($actor, $reference, $expectedRevision, KycState::Verified, $reason, $evidenceReference, $resolvedChecks,
            ['risk_level' => $riskLevel, 'document_expires_on' => $documentExpiresOn]);
    }

    /** @param list<string> $resolvedChecks */
    public function review(User $actor, string $reference, int $expectedRevision, KycState $decision, string $reason, string $evidenceReference, array $resolvedChecks = []): ConsumerVerificationRevision
    {
        return $this->recordReview($actor, $reference, $expectedRevision, $decision, $reason, $evidenceReference, $resolvedChecks);
    }

    /**
     * @param  list<string>  $resolvedChecks
     * @param  array{risk_level:string,document_expires_on:string}|null  $recommendation
     */
    private function recordReview(User $actor, string $reference, int $expectedRevision, KycState $decision, string $reason, string $evidenceReference, array $resolvedChecks, ?array $recommendation = null): ConsumerVerificationRevision
    {
        $revision = $this->access->scopeQuery(ConsumerVerificationRevision::query(), $actor, 'kyc.review')
            ->where('reference', $reference)->firstOrFail();
        $this->access->authorize($actor, 'kyc.review', customerId: $revision->customer_id);
        AuditService::assertCode($reason);
        AuditService::assertReference($evidenceReference);
        if (! in_array($decision, [KycState::Verified, KycState::Rejected, KycState::ReverificationRequired], true)
            || array_diff($resolvedChecks, ConsumerVerificationDecision::CHECKS) !== [] || count(array_unique($resolvedChecks)) !== count($resolvedChecks)) {
            throw new ConsumerIdentityFailure('invalid_identity_review', 422);
        }

        sort($resolvedChecks);

        return DB::transaction(function () use ($actor, $revision, $expectedRevision, $decision, $reason, $evidenceReference, $resolvedChecks, $recommendation): ConsumerVerificationRevision {
            $identity = $this->lockedIdentity($revision->consumer_identity_id);
            $current = ConsumerVerificationRevision::query()->whereKey($revision->id)->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, 'kyc.review', customerId: $current->customer_id);
            if ($identity->verification_revision !== $expectedRevision || $current->revision !== $expectedRevision
                || ($current->manual_decided_at !== null && $decision !== KycState::ReverificationRequired)) {
                throw new ConsumerIdentityFailure('identity_review_conflict', 409);
            }
            if ($actor->id === $identity->user_id) {
                throw new ConsumerIdentityFailure('self_identity_review_denied', 403);
            }
            $reviewContext = null;
            if ($decision === KycState::Verified) {
                $checks = $current->checks;
                if (! is_array($checks) || count($checks) !== count(ConsumerVerificationDecision::CHECKS)
                    || in_array('pending', $checks, true) || in_array('rejected', $checks, true)
                    || array_diff(array_values(array_unique([...(array) $current->unresolved_checks, ...array_keys(array_filter($checks, fn (string $check): bool => in_array($check, ['hit', 'rejected'], true)))])), $resolvedChecks) !== []) {
                    throw new ConsumerIdentityFailure('screening_review_unresolved', 409);
                }
                if (! $this->hasValidityPolicy()) {
                    throw new ConsumerIdentityFailure('identity_policy_required', 503);
                }
                if ($current->policy_reference !== config('pull2spend.consumer.policy_reference')) {
                    throw new ConsumerIdentityFailure('identity_policy_changed', 409);
                }
                if ($current->provider_observed_at === null || $current->provider_observed_at <= now()->subSeconds((int) config('pull2spend.consumer.screening_max_age_seconds', 86400))) {
                    throw new ConsumerIdentityFailure('screening_stale', 409);
                }
                $observationHash = FoundationIdempotency::fingerprint(['checks' => $current->checks, 'unresolved_checks' => $current->unresolved_checks,
                    'observed_at' => $current->provider_observed_at->toIso8601String(), 'policy_reference' => $current->policy_reference, 'evidence' => $current->evidence]);
                if ($recommendation !== null) {
                    $reviewContext = [...$recommendation, 'observation_hash' => $observationHash];
                } else {
                    $proposal = ConsumerReviewDecision::where('consumer_verification_revision_id', $current->id)
                        ->where('decision', 'recommend_verified')->latest('id')->first();
                    if ($proposal === null) {
                        throw new ConsumerIdentityFailure('examiner_recommendation_required', 409);
                    }
                    if ($proposal->actor_id === $actor->id) {
                        throw new ConsumerIdentityFailure('independent_checker_required', 409);
                    }
                    $reviewContext = $proposal->review_context;
                    if (! is_array($reviewContext) || ! hash_equals($observationHash, $reviewContext['observation_hash'])
                        || $proposal->resolved_checks !== $resolvedChecks) {
                        throw new ConsumerIdentityFailure('examiner_recommendation_stale', 409);
                    }
                    if (CarbonImmutable::parse($reviewContext['document_expires_on'], 'UTC')->endOfDay() <= now()) {
                        throw new ConsumerIdentityFailure('identity_document_expired', 409);
                    }
                    $reviewContext['examiner_decision_reference'] = $proposal->reference;
                }

            }
            ConsumerReviewDecision::create(['consumer_verification_revision_id' => $current->id, 'actor_id' => $actor->id,
                'decision' => $recommendation !== null ? 'recommend_verified' : $decision->value, 'reason' => $reason, 'evidence_reference' => $evidenceReference, 'resolved_checks' => $resolvedChecks, 'review_context' => $reviewContext]);
            if ($recommendation !== null) {
                $current->update(['state' => KycState::UnderReview, 'manual_review_required' => true]);
                $this->applyState($identity, $current, KycState::UnderReview);
                $this->audit->record($actor->id, 'identity.approval_recommended', $current->reference, $reason, customerId: $identity->user_id,
                    context: ['evidence_reference' => $evidenceReference]);

                return $current;
            }
            $current->update(['state' => $decision, 'manual_decided_at' => now(), 'manual_review_required' => true, 'unresolved_checks' => $decision === KycState::Verified ? [] : $current->unresolved_checks]);
            $this->applyState($identity, $current, $decision, $reviewContext);
            $this->audit->record($actor->id, 'identity.reviewed', $current->reference, $reason, customerId: $identity->user_id, context: ['evidence_reference' => $evidenceReference, 'state' => $decision->value]);

            return $current;
        }, attempts: 5);
    }

    public function restrict(User $actor, string $identityReference, AccountRestriction $restriction, string $reason, string $evidenceReference): ConsumerIdentity
    {
        $identity = $this->access->scopeQuery(ConsumerIdentity::query(), $actor, 'consumer.restrict', 'user_id', null)
            ->where('reference', $identityReference)->firstOrFail();
        $this->access->authorize($actor, 'consumer.restrict', customerId: $identity->user_id);
        AuditService::assertCode($reason);
        AuditService::assertReference($evidenceReference);

        return DB::transaction(function () use ($identity, $actor, $restriction, $reason, $evidenceReference): ConsumerIdentity {
            $current = $this->lockedIdentity($identity->id);
            $this->access->authorize($actor, 'consumer.restrict', customerId: $current->user_id);
            $previous = $current->restriction;
            $current->update(['restriction' => $restriction]);
            $audit = $this->audit->record($actor->id, 'identity.restriction_changed', $current->reference, $reason, customerId: $current->user_id, context: ['previous_state' => $previous->value, 'state' => $restriction->value, 'evidence_reference' => $evidenceReference]);
            $this->notifications->enqueue($current->user_id, 'security_event', $audit->reference);
            if ($restriction === AccountRestriction::None && $current->kyc_state === KycState::Verified && $current->expires_at > now()) {
                $revision = ConsumerVerificationRevision::query()->where('consumer_identity_id', $current->id)->where('revision', $current->verification_revision)->firstOrFail();
                $this->recordEnrollment($current, $revision);
            }

            return $current;
        }, attempts: 5);
    }

    /** @return array<string, mixed> */
    public function current(ConsumerSession $session): array
    {
        $this->security->assertSession($session);
        $identity = $this->identityFor($session);
        $revision = ConsumerVerificationRevision::query()->where('consumer_identity_id', $identity->id)->where('revision', $identity->verification_revision)->first();

        return $this->view($identity, $revision);
    }

    /** @return array<string, mixed> */
    public function view(ConsumerIdentity $identity, ?ConsumerVerificationRevision $revision): array
    {
        return ['reference' => $revision?->reference, 'state' => $identity->kyc_state->value, 'revision' => $identity->verification_revision,
            'provider_available' => $this->gateway->isAvailable(), 'policy_reference' => $identity->policy_reference,
            'created_at' => $revision?->created_at?->toIso8601String(), 'updated_at' => $revision?->updated_at?->toIso8601String(),
            'verified_at' => $identity->verified_at?->toIso8601String(), 'expires_at' => $identity->expires_at?->toIso8601String(),
            'next_action' => match ($identity->kyc_state) {
                KycState::Rejected, KycState::ReverificationRequired => 'submit_supported_remediation', KycState::UnderReview => 'await_compliance_review', KycState::Verified => 'review_eligibility', default => $this->gateway->isAvailable() ? 'complete_identity_verification' : 'contact_support'
            },
            'reason_codes' => $this->reasons($identity)];
    }

    /** @return list<string> */
    private function reasons(ConsumerIdentity $identity): array
    {
        $reasons = [];
        $revision = ConsumerVerificationRevision::query()->where('consumer_identity_id', $identity->id)->where('revision', $identity->verification_revision)->first();
        if ($identity->contact_verified_at === null) {
            $reasons[] = 'contact_unverified';
        }
        if ($identity->restriction !== AccountRestriction::None) {
            $reasons[] = 'account_restricted';
        }
        if ($identity->kyc_state !== KycState::Verified || $revision?->state !== KycState::Verified || $identity->expires_at === null || $identity->expires_at <= now()) {
            $reasons[] = 'kyc_required';
        }
        if ($revision?->provider_observed_at === null || $revision->provider_observed_at <= now()->subSeconds((int) config('pull2spend.consumer.screening_max_age_seconds', 86400))) {
            $reasons[] = 'screening_stale';
        }
        if (! config('pull2spend.consumer.policy_approved', false) || $identity->policy_reference !== config('pull2spend.consumer.policy_reference')) {
            $reasons[] = 'identity_policy_unapproved';
        }
        if (config('pull2spend.consumer.verification_mode') === 'fixture' || ! $this->gateway->isAvailable()
            || $revision === null || $revision->source === 'local-fixture' || ! hash_equals($revision->source, $this->gateway->source())) {
            $reasons[] = 'verification_provider_not_selected';
        }

        return $reasons;
    }

    private function identityFor(ConsumerSession $session, bool $lock = false): ConsumerIdentity
    {
        $identity = $lock ? $this->lockedIdentity($session->consumer_identity_id) : ConsumerIdentity::find($session->consumer_identity_id);
        if ($identity === null || $identity->user_id !== $session->user_id || $identity->contact_verified_at === null) {
            throw new ConsumerIdentityFailure('contact_unverified');
        }

        return $identity;
    }

    /** SQLite needs a write reservation before reads; its SELECT FOR UPDATE does not lock rows. */
    private function lockedIdentity(int $id): ConsumerIdentity
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::table('consumer_identities')->where('id', $id)->update(['id' => DB::raw('id')]);
        }

        return ConsumerIdentity::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    private function hasValidityPolicy(): bool
    {
        $seconds = config('pull2spend.consumer.verification_validity_seconds');

        return is_int($seconds) && $seconds > 0 && $seconds <= 315360000;
    }

    private function applyState(ConsumerIdentity $identity, ConsumerVerificationRevision $revision, KycState $state, ?array $reviewContext = null): void
    {
        $previous = $identity->kyc_state;
        $verifiedAt = $state === KycState::Verified ? ($identity->verified_at ?? now()) : null;
        $expiresAt = $state === KycState::Verified ? ($identity->expires_at ?? now()->addSeconds(config('pull2spend.consumer.verification_validity_seconds'))) : null;
        if ($state === KycState::Verified && $reviewContext !== null) {
            $seconds = $reviewContext['risk_level'] === 'elevated'
                ? (int) config('pull2spend.consumer.elevated_verification_validity_seconds', 7776000)
                : (int) config('pull2spend.consumer.verification_validity_seconds');
            $expiresAt = min($expiresAt, now()->addSeconds($seconds), CarbonImmutable::parse($reviewContext['document_expires_on'], 'UTC')->endOfDay());
        }
        $identity->update(['kyc_state' => $state, 'verified_at' => $verifiedAt, 'expires_at' => $expiresAt]);
        if ($state === KycState::Verified && $identity->restriction === AccountRestriction::None && $expiresAt > now()) {
            $this->recordEnrollment($identity, $revision);
        }
        if ($previous !== $state) {
            $this->notifications->enqueue($identity->user_id, 'case_update', $revision->reference.':'.Str::uuid());
        }
    }

    private function recordEnrollment(ConsumerIdentity $identity, ConsumerVerificationRevision $revision): void
    {
        if ($identity->contact_verified_at === null) {
            return;
        }

        ConsumerEnrollmentIntent::query()->createOrFirst(['consumer_identity_id' => $identity->id, 'purpose' => 'initial_economic_enrollment'], [
            'consumer_verification_revision_id' => $revision->id, 'policy_reference' => $revision->policy_reference,
            'payload' => ['identity_reference' => $identity->reference, 'revision_reference' => $revision->reference,
                'source' => $revision->source, 'verification_assurance' => $revision->source === 'local-fixture' ? 'local_fixture' : 'authenticated_provider',
                'financial_authority' => false, 'requires_downstream_eligibility' => true, 'policy_approved_at_emission' => (bool) config('pull2spend.consumer.policy_approved', false), 'wallet_intent_reference' => 'wallet:'.$identity->reference, 'card_intent_reference' => 'card:'.$identity->reference],
        ]);
    }
}

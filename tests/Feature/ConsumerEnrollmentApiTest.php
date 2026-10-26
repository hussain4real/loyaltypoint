<?php

declare(strict_types=1);

use App\Enums\AccountRestriction;
use App\Enums\KycState;
use App\Enums\StaffRole;
use App\Models\ConsumerIdentity;
use App\Models\ConsumerSession;
use App\Models\ConsumerVerificationRevision;
use App\Models\StaffMembership;
use App\Models\User;
use App\Services\ConsumerVerificationDecision;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['pull2spend.consumer.enabled' => true, 'pull2spend.consumer.verification_mode' => 'fixture',
        'pull2spend.consumer.verification_key' => str_repeat('fixture-callback', 3), 'pull2spend.consumer.policy_reference' => 'fixture-policy-v1',
        'pull2spend.consumer.verification_validity_seconds' => 3600, 'pull2spend.consumer.policy_approved' => false]);
});

/** @return array{ConsumerSession, string, array<string, string>} */
function enrollmentApiSession(): array
{
    $session = ConsumerSession::factory()->create();
    $identity = ConsumerIdentity::findOrFail($session->consumer_identity_id);
    $token = User::findOrFail($session->user_id)->createToken('Enrollment API test', ['consumer:identity'], now()->addHour());
    $session->update(['personal_access_token_id' => $token->accessToken->id]);

    return [$session, $token->plainTextToken, ['identity_document_reference' => 'fixture:'.$identity->reference.'/document',
        'liveness_evidence_reference' => 'fixture:'.$identity->reference.'/liveness', 'identity_details_reference' => 'fixture:'.$identity->reference.'/details']];
}

function enrollmentCallbackBody(ConsumerVerificationRevision $revision, array $overrides = []): string
{
    return json_encode(array_replace(['event_reference' => (string) Str::uuid(), 'revision_reference' => $revision->reference,
        'operation_reference' => $revision->operation_reference, 'checks' => array_fill_keys(ConsumerVerificationDecision::CHECKS, 'clear'),
        'occurred_at' => now()->toIso8601String(), 'evidence_reference' => 'fixture:provider-evidence'], $overrides), JSON_THROW_ON_ERROR);
}

function enrollmentCallbackHeaders(string $body): array
{
    $timestamp = (string) now()->timestamp;

    return ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_X_CALLBACK_TIMESTAMP' => $timestamp,
        'HTTP_X_CALLBACK_SIGNATURE' => hash_hmac('sha256', $timestamp.'.'.$body, config('pull2spend.consumer.verification_key'))];
}

function enrollmentStaffLogin(TestCase $test, StaffRole $role, ?array $customers = null): StaffMembership
{
    config(['pull2spend.backoffice_enabled' => true]);
    $staff = StaffMembership::factory()->enrolled()->create(['role' => $role, 'partner_ids' => null, 'customer_ids' => $customers]);
    $response = $test->post('/backoffice/login', ['email' => $staff->user->email, 'password' => 'password'])->assertRedirect(route('backoffice.mfa'));
    $test->withCookie(config('session.cookie'), $response->getCookie(config('session.cookie'))->getValue());
    $response = $test->post('/backoffice/mfa', ['code' => '279037'])->assertRedirect(route('backoffice.dashboard'));
    $test->withCookie(config('session.cookie'), $response->getCookie(config('session.cookie'))->getValue());

    return $staff;
}

it('returns 401 without a real current consumer session', function (string $path): void {
    $this->getJson($path)->assertUnauthorized();
})->with(['/api/v2/eligibility', '/api/v2/enrollments/current']);

it('returns raw safe enrollment and eligibility JSON with honest default unavailable service', function (): void {
    config(['pull2spend.consumer.verification_mode' => 'unavailable']);
    [$session, $token] = enrollmentApiSession();
    $this->withToken($token)->getJson('/api/v2/enrollments/current')->assertOk()
        ->assertJsonPath('state', 'not_started')->assertJsonPath('reference', null)->assertJsonPath('provider_available', false)->assertJsonMissingPath('data');
    $this->getJson('/api/v2/eligibility')->assertOk()->assertJsonPath('kyc_state', 'not_started')
        ->assertJsonPath('allowed_actions', ['onboarding', 'support'])->assertJsonMissingPath('checks')->assertJsonMissingPath('evidence');
    $this->postJson('/api/v2/identity/uploads', ['kind' => 'identity_document'])->assertStatus(503)
        ->assertJsonPath('error.code', 'verification_provider_unavailable');
});

it('accepts an owned fixture enrollment with a stable reference without granting financial access', function (): void {
    [$session, $token, $evidence] = enrollmentApiSession();
    $first = $this->withToken($token)->withHeader('Idempotency-Key', 'enrollment-123')->postJson('/api/v2/enrollments', $evidence)
        ->assertAccepted()->assertJsonPath('state', 'pending')->assertJsonMissingPath('data')->assertJsonMissingPath('evidence');
    $this->postJson('/api/v2/enrollments', $evidence)->assertAccepted()->assertJsonPath('reference', $first->json('reference'));
    $this->assertDatabaseCount('consumer_verification_revisions', 1);
    $this->assertDatabaseCount('consumer_enrollment_intents', 0);
});

it('returns 422 for missing evidence and rejects client approval fields', function (): void {
    [, $token, $evidence] = enrollmentApiSession();
    $this->withToken($token)->withHeader('Idempotency-Key', 'enrollment-123')->postJson('/api/v2/enrollments', [])
        ->assertUnprocessable()->assertJsonValidationErrors(['identity_document_reference', 'liveness_evidence_reference', 'identity_details_reference']);
    $this->postJson('/api/v2/enrollments', [...$evidence, 'kyc_state' => 'verified'])->assertUnprocessable()->assertJsonPath('error.code', 'invalid_identity_evidence');
    $this->assertDatabaseCount('consumer_verification_revisions', 0);
});

it('returns 404 for another consumers evidence rather than storing its reference', function (): void {
    [, $token] = enrollmentApiSession();
    [, , $foreignEvidence] = enrollmentApiSession();
    $this->withToken($token)->withHeader('Idempotency-Key', 'enrollment-123')->postJson('/api/v2/enrollments', $foreignEvidence)
        ->assertNotFound()->assertJsonPath('error.code', 'identity_evidence_not_owned');
    $this->assertDatabaseCount('consumer_verification_revisions', 0);
});

it('authenticates and deduplicates callback receipt atomically with its enrollment outcome', function (): void {
    $this->freezeTime();
    $revision = ConsumerVerificationRevision::factory()->create();
    $body = enrollmentCallbackBody($revision);
    $first = $this->call('POST', '/api/v2/identity/callbacks/local-fixture', server: enrollmentCallbackHeaders($body), content: $body)
        ->assertAccepted()->assertJsonPath('status', 'accepted');
    $this->call('POST', '/api/v2/identity/callbacks/local-fixture', server: enrollmentCallbackHeaders($body), content: $body)
        ->assertAccepted()->assertJsonPath('reference', $first->json('reference'));

    $this->assertDatabaseCount('consumer_verification_receipts', 1);
    $this->assertDatabaseCount('consumer_enrollment_intents', 1);
    $this->assertDatabaseHas('consumer_identities', ['id' => $revision->consumer_identity_id, 'kyc_state' => 'verified']);
});

it('rejects callback authentication schema and mismatched subjects without an enrollment outcome', function (string $problem, int $status): void {
    $revision = ConsumerVerificationRevision::factory()->create();
    $body = enrollmentCallbackBody($revision, match ($problem) {
        'schema' => ['raw_identity_document' => 'must-not-persist'],
        'subject' => ['operation_reference' => 'other-consumer-operation'],
        'missing_checks' => ['checks' => ['document' => 'clear']],
        'invalid_date' => ['occurred_at' => '2026-10-26T10:00:00+24:00'],
        default => [],
    });
    $headers = enrollmentCallbackHeaders($body);
    if ($problem === 'authentication') {
        $headers['HTTP_X_CALLBACK_SIGNATURE'] = 'invalid';
    }
    $this->call('POST', '/api/v2/identity/callbacks/local-fixture', server: $headers, content: $body)->assertStatus($status);
    $this->assertDatabaseCount('consumer_verification_receipts', 0);
    $this->assertDatabaseCount('consumer_enrollment_intents', 0);
})->with([['authentication', 401], ['schema', 422], ['subject', 404], ['missing_checks', 422], ['invalid_date', 422]]);

it('denies evidence to support and foreign scopes while auditing authorized compliance access', function (StaffRole $role, bool $foreign, int $status): void {
    $this->travelTo(CarbonImmutable::createFromTimestampUTC(2000000000));
    $revision = ConsumerVerificationRevision::factory()->create();
    enrollmentStaffLogin($this, $role, $foreign ? [$revision->customer_id + 1] : null);
    $response = $this->get('/backoffice/identity/reviews/'.$revision->reference.'/evidence', ['Accept' => 'application/json'])->assertStatus($status);
    if ($status === 200) {
        $response->assertJsonPath('viewer_available', false)->assertJsonMissingPath('mfa_secret');
        $this->assertDatabaseHas('audit_events', ['action' => 'identity.evidence_viewed', 'customer_id' => $revision->customer_id]);
    }
})->with([[StaffRole::Support, false, 403], [StaffRole::Compliance, true, 404], [StaffRole::Compliance, false, 200]]);

it('renders the scoped compliance queue with a working decision form and preserves review denial safely', function (): void {
    $this->travelTo(CarbonImmutable::createFromTimestampUTC(2000000000));
    $revision = ConsumerVerificationRevision::factory()->create(['state' => 'under_review']);
    enrollmentStaffLogin($this, StaffRole::Compliance);
    $this->get('/backoffice/identity/reviews')->assertOk()->assertSee('Identity review queue')->assertSee($revision->reference);
    $this->from('/backoffice/identity/reviews')->post('/backoffice/identity/reviews/'.$revision->reference,
        ['expected_revision' => 1, 'decision' => 'verified', 'reason' => 'review_completed', 'evidence_reference' => 'review:evidence'])
        ->assertRedirect('/backoffice/identity/reviews')->assertSessionHasErrors('review');
    $this->assertDatabaseCount('consumer_enrollment_intents', 0);
});

it('lets compliance find a verified consumer and apply a separate auditable restriction', function (): void {
    $this->travelTo(CarbonImmutable::createFromTimestampUTC(2000000000));
    $revision = ConsumerVerificationRevision::factory()->create(['state' => 'verified']);
    $identity = ConsumerIdentity::findOrFail($revision->consumer_identity_id);
    $identity->update(['verified_at' => now(), 'expires_at' => now()->addHour()]);
    enrollmentStaffLogin($this, StaffRole::Compliance);
    $this->get('/backoffice/identity/reviews?state=verified')->assertOk()->assertSee($revision->reference)->assertSee('Account restriction');
    $this->from('/backoffice/identity/reviews?state=verified')->post('/backoffice/identity/restrictions/'.$identity->reference,
        ['restriction' => 'suspended', 'reason' => 'risk_restriction', 'evidence_reference' => 'review:risk-evidence'])->assertRedirect();

    expect($identity->fresh())->kyc_state->toBe(KycState::Verified)->restriction->toBe(AccountRestriction::Suspended);
    $this->assertDatabaseHas('audit_events', ['action' => 'identity.restriction_changed', 'customer_id' => $identity->user_id]);
});

it('does not reveal revision or identity existence before checking review permissions and customer scope', function (StaffRole $role, bool $foreign, int $status): void {
    $this->travelTo(CarbonImmutable::createFromTimestampUTC(2000000000));
    $revision = ConsumerVerificationRevision::factory()->create();
    $identity = ConsumerIdentity::findOrFail($revision->consumer_identity_id);
    enrollmentStaffLogin($this, $role, $foreign ? [$revision->customer_id + 1] : null);
    foreach ([true, false] as $exists) {
        $reference = $exists ? $revision->reference : (string) Str::uuid();
        $identityReference = $exists ? $identity->reference : (string) Str::uuid();
        $this->get('/backoffice/identity/reviews/'.$reference.'/evidence', ['Accept' => 'application/json'])->assertStatus($status);
        $this->post('/backoffice/identity/reviews/'.$reference,
            ['expected_revision' => 1, 'decision' => 'rejected', 'reason' => 'review_rejected', 'evidence_reference' => 'review:evidence'])->assertStatus($status);
        $this->post('/backoffice/identity/restrictions/'.$identityReference,
            ['restriction' => 'suspended', 'reason' => 'review_restriction', 'evidence_reference' => 'review:evidence'])->assertStatus($status);
    }
    $this->assertDatabaseCount('consumer_review_decisions', 0);
    expect($identity->fresh()->restriction)->toBe(AccountRestriction::None);
})->with([[StaffRole::Support, false, 403], [StaffRole::Compliance, true, 404]]);

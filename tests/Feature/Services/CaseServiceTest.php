<?php

declare(strict_types=1);

use App\Enums\StaffRole;
use App\Models\OperationalCase;
use App\Models\StaffMembership;
use App\Models\User;
use App\Services\CaseService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

/** Arrange an already verified session; MFA behavior has separate endpoint coverage. */
function caseStaffSession(?array $partners = null, ?array $customers = null, StaffRole $role = StaffRole::Operations): User
{
    config(['pull2spend.backoffice_enabled' => true]);
    $user = User::factory()->create();
    $session = app('session')->driver();
    $session->start();
    $binding = hash('sha256', $session->getId());
    $staff = StaffMembership::factory()->enrolled()->create(['user_id' => $user->id, 'role' => $role, 'partner_ids' => $partners, 'customer_ids' => $customers]);
    $staff->session_binding = $binding;
    $staff->save();
    $session->put(['staff_mfa_user' => $user->id, 'staff_mfa_at' => now()->timestamp, 'staff_mfa_binding' => $binding]);
    $request = Request::create('/backoffice');
    $request->setLaravelSession($session);
    $request->setUserResolver(fn () => $user);
    app()->instance('request', $request);
    $request->setUserResolver(fn () => $user);

    return $user;
}

test('opens one owned operations case with SLA and immutable history for repeated failures', function (): void {
    $this->freezeTime();
    config(['pull2spend.cases.sla_hours' => 4]);
    $service = app(CaseService::class);
    $first = $service->open('integration', 'event-123', 7, 9);
    $repeat = $service->open('integration', 'event-123', 7, 9);

    expect($first->id)->toBe($repeat->id);
    expect($first)->state->toBe('open')->owner_team->toBe('operations')->owner_id->toBeNull();
    expect($first->due_at->equalTo(now()->startOfSecond()->addHours(4)))->toBeTrue();
    $this->assertDatabaseCount('operational_cases', 1);
    $this->assertDatabaseCount('case_events', 1);
    $this->assertDatabaseHas('audit_events', ['action' => 'case.opened', 'target_reference' => $first->reference, 'reason' => 'investigation_required']);
});

test('rejects reuse of a case identity under a different scope', function (): void {
    $service = app(CaseService::class);
    $service->open('integration', 'event-123', 7, 9);

    expect(fn () => $service->open('integration', 'event-123', 8, 9))->toThrow(InvalidArgumentException::class);
    $this->assertDatabaseCount('operational_cases', 1);
});

test('assigns and moves a case through all workflow states and requires evidence to close', function (): void {
    $actor = caseStaffSession();
    $service = app(CaseService::class);
    $case = $service->open('redemption', 'operation-123', 7, 9);
    $case = $service->transition($actor, $case, 'assigned', 'assigned_for_review', ownerId: $actor->id);
    $case = $service->transition($actor, $case, 'in_progress', 'review_started');
    $case = $service->transition($actor, $case, 'awaiting_external', 'partner_evidence_requested');
    expect($case->fresh()->state)->toBe('awaiting_external');
    expect(fn () => $service->transition($actor, $case, 'resolved', 'partner_confirmed'))->toThrow(InvalidArgumentException::class);
    expect($case->fresh()->state)->toBe('awaiting_external');
    $case = $service->transition($actor, $case, 'resolved', 'partner_confirmed', 'partner-status-123');
    $case = $service->transition($actor, $case, 'closed', 'resolution_reviewed');

    expect($case->fresh())->state->toBe('closed')->owner_id->toBe($actor->id)->resolution_code->toBe('partner_confirmed')->evidence_reference->toBe('partner-status-123');
    expect($case->fresh()->closed_at)->not->toBeNull();
    expect($case->events()->orderBy('id')->pluck('to_state')->all())->toBe(['open', 'assigned', 'in_progress', 'awaiting_external', 'resolved', 'closed']);
    expect($case->events()->latest('id')->first()->actor_id)->toBe($actor->id);
    expect(fn () => $service->transition($actor, $case, 'in_progress', 'try_reopen'))->toThrow(InvalidArgumentException::class);
});

test('reopening a resolved case preserves evidence in history and clears current resolution', function (): void {
    $actor = caseStaffSession();
    $service = app(CaseService::class);
    $case = $service->open('connector', 'incident-123', ownerId: $actor->id);
    $case = $service->transition($actor, $case, 'in_progress', 'review_started');
    $case = $service->transition($actor, $case, 'resolved', 'partner_confirmed', 'evidence-123');
    $case = $service->transition($actor, $case, 'in_progress', 'additional_review_required');

    expect($case->fresh())->resolution_code->toBeNull()->evidence_reference->toBeNull()->resolved_at->toBeNull();
    expect($case->events()->where('to_state', 'resolved')->first()->evidence_reference)->toBe('evidence-123');
});

test('requires a named eligible owner before starting work', function (): void {
    $actor = caseStaffSession([7], [9]);
    $service = app(CaseService::class);
    $case = $service->open('integration', 'event-123', 7, 9);
    expect(fn () => $service->transition($actor, $case, 'assigned', 'assigned_for_review'))->toThrow(InvalidArgumentException::class);
    $other = StaffMembership::factory()->create(['role' => StaffRole::Operations, 'partner_ids' => [8], 'customer_ids' => null]);
    expect(fn () => $service->transition($actor, $case, 'assigned', 'assigned_for_review', ownerId: $other->user_id))->toThrow(InvalidArgumentException::class);
    expect($case->fresh()->state)->toBe('open');
});

test('requires staff MFA and scope on service mutations as well as views', function (): void {
    $actor = caseStaffSession([7], [9]);
    $service = app(CaseService::class);
    $other = $service->open('integration', 'other-event', 8, 9);
    $allowed = $service->open('integration', 'allowed-event', 7, 9);
    $differentCustomer = $service->open('integration', 'customer-event', 7, 10);
    $global = $service->open('integration', 'global-event');

    expect($service->visibleTo($actor)->pluck('id')->all())->toBe([$allowed->id]);
    expect(fn () => $service->maskedView($actor, $other))->toThrow(AuthorizationException::class);
    expect(fn () => $service->transition($actor, $differentCustomer, 'assigned', 'assigned_for_review', ownerId: $actor->id))->toThrow(AuthorizationException::class);
    expect(fn () => $service->maskedView($actor, $global))->toThrow(AuthorizationException::class);
    app('request')->session()->forget('staff_mfa_at');
    expect(fn () => $service->transition($actor, $allowed, 'assigned', 'assigned_for_review', ownerId: $actor->id))->toThrow(AuthorizationException::class);
    expect(fn () => $service->open('integration', 'staff-created', 7, 9, $actor->id))->toThrow(AuthorizationException::class);
});

test('keeps partner viewers read only and omits customer contact information', function (): void {
    $actor = caseStaffSession([7], [9], StaffRole::PartnerViewer);
    $service = app(CaseService::class);
    $case = $service->open('integration', 'event-123', 7, 9);
    $view = $service->maskedView($actor, $case);

    expect($view)->reference->toBe($case->reference)->customer_reference->toBe('customer-9')->owner_reference->toBe('operations');
    expect(json_encode($view))->not->toContain($actor->email)->not->toContain($actor->password);
    $this->assertDatabaseHas('audit_events', ['actor_id' => $actor->id, 'action' => 'case.viewed', 'target_reference' => $case->reference]);
    expect(fn () => $service->transition($actor, $case, 'assigned', 'assigned_for_review', ownerId: $actor->id))->toThrow(AuthorizationException::class);
});

test('marks overdue work but resolved and closed cases stop the SLA clock', function (): void {
    $this->freezeTime();
    $case = OperationalCase::factory()->create(['due_at' => now()->subSecond()]);
    expect($case->isOverdue())->toBeTrue();
    $case->state = 'resolved';
    expect($case->isOverdue())->toBeFalse();
    $case->state = 'closed';
    expect($case->isOverdue())->toBeFalse();
});

test('keeps case-distinct opaque source references separate with stable exact identity', function (): void {
    $service = app(CaseService::class);
    $upper = $service->open('integration', 'Op:A');
    $lower = $service->open('integration', 'Op:a');

    expect($upper->id)->not->toBe($lower->id);
    expect($upper->identity_hash)->not->toBe($lower->identity_hash);
    expect($service->open('integration', 'Op:A')->id)->toBe($upper->id);
    expect($service->open('integration', 'Op:a')->id)->toBe($lower->id);
    $this->assertDatabaseCount('operational_cases', 2);
    $this->assertDatabaseCount('case_events', 2);
});

test('reassigns a resolved case after its owner loses eligibility and preserves resolution history', function (array $ownerChange): void {
    $actor = caseStaffSession();
    $oldOwner = StaffMembership::factory()->create(['role' => StaffRole::Operations, 'partner_ids' => [7], 'customer_ids' => [9]]);
    $replacement = StaffMembership::factory()->create(['role' => StaffRole::Operations, 'partner_ids' => [7], 'customer_ids' => [9]]);
    $service = app(CaseService::class);
    $case = $service->open('connector', 'incident-owner-change', 7, 9, ownerId: $oldOwner->user_id);
    $case = $service->transition($actor, $case, 'in_progress', 'review_started');
    $case = $service->transition($actor, $case, 'resolved', 'partner_confirmed', 'original-evidence');
    $oldOwner->update($ownerChange);
    expect(fn () => $service->transition($actor, $case, 'closed', 'resolution_reviewed'))->toThrow(InvalidArgumentException::class);

    $case = $service->transition($actor, $case, 'assigned', 'replacement_owner_assigned', ownerId: $replacement->user_id);
    expect($case->fresh())->state->toBe('assigned')->owner_id->toBe($replacement->user_id)
        ->resolution_code->toBeNull()->evidence_reference->toBeNull()->resolved_at->toBeNull();
    expect($case->events()->where('to_state', 'resolved')->first()->evidence_reference)->toBe('original-evidence');
    $case = $service->transition($actor, $case, 'in_progress', 'replacement_review_started');
    $case = $service->transition($actor, $case, 'resolved', 'replacement_confirmed', 'replacement-evidence');
    $case = $service->transition($actor, $case, 'closed', 'resolution_reviewed');

    expect($case->fresh())->state->toBe('closed')->owner_id->toBe($replacement->user_id)->evidence_reference->toBe('replacement-evidence');
    expect($case->events()->where('to_state', 'resolved')->orderBy('id')->pluck('evidence_reference')->all())->toBe(['original-evidence', 'replacement-evidence']);
})->with([
    'disabled owner' => [['enabled' => false]],
    'owner moved outside partner scope' => [['partner_ids' => [8]]],
]);

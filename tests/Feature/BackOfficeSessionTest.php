<?php

declare(strict_types=1);

use App\Enums\StaffRole;
use App\Models\AuditEvent;
use App\Models\OperationalCase;
use App\Models\StaffElevation;
use App\Models\StaffMembership;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['pull2spend.backoffice_enabled' => true]);
});

/** @param array<string, mixed> $data */
function backofficePost(TestCase $test, string $uri, array $data = []): TestResponse
{
    $response = $test->post($uri, $data);
    $cookie = $response->getCookie(config('session.cookie'));
    if ($cookie !== null) {
        $test->withCookie(config('session.cookie'), $cookie->getValue());
    }

    return $response;
}

function signInBackOffice(TestCase $test, StaffRole $role = StaffRole::Operations, ?array $partners = null, ?array $customers = null): StaffMembership
{
    $staff = StaffMembership::factory()->enrolled()->create(['role' => $role, 'partner_ids' => $partners, 'customer_ids' => $customers]);
    backofficePost($test, '/backoffice/login', ['email' => $staff->user->email, 'password' => 'password'])->assertRedirect(route('backoffice.mfa'));
    backofficePost($test, '/backoffice/mfa', ['code' => '279037'])->assertRedirect(route('backoffice.dashboard'));

    return $staff;
}

it('keeps the staff shell disabled unless explicitly enabled', function (): void {
    config(['pull2spend.backoffice_enabled' => false]);
    $this->get('/backoffice/login')->assertNotFound();
    $this->get('/backoffice')->assertNotFound();
});

it('requires a web password and authenticator instead of a customer token', function (): void {
    $this->get('/backoffice')->assertRedirect(route('backoffice.login'));
    $user = User::factory()->create();
    Sanctum::actingAs($user, ['*']);
    $this->get('/backoffice')->assertRedirect(route('backoffice.login'));
    backofficePost($this, '/backoffice/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
});

it('does not grant access after password login until authenticator verification', function (): void {
    $this->travelTo(CarbonImmutable::createFromTimestampUTC(2000000000));
    $staff = StaffMembership::factory()->enrolled()->create(['role' => StaffRole::Operations, 'partner_ids' => null, 'customer_ids' => null]);
    backofficePost($this, '/backoffice/login', ['email' => $staff->user->email, 'password' => 'password'])->assertRedirect(route('backoffice.mfa'));
    $this->get('/backoffice')->assertRedirect(route('backoffice.mfa'));
    backofficePost($this, '/backoffice/mfa', ['code' => '000000'])->assertSessionHasErrors('code');
    backofficePost($this, '/backoffice/mfa', ['code' => '279037'])->assertRedirect(route('backoffice.dashboard'));
    $this->get('/backoffice')->assertOk()->assertSee('Operations overview')->assertHeader('Cache-Control', 'no-store, private');
    $this->assertDatabaseHas('audit_events', ['actor_id' => $staff->user_id, 'action' => 'staff.mfa_verified']);
});

it('enrolls a factor only with the invitation and never exposes a stored factor', function (): void {
    $this->travelTo(CarbonImmutable::createFromTimestampUTC(2000000000));
    $staff = StaffMembership::factory()->create(['enrollment_token_hash' => hash('sha256', 'private-invitation')]);
    backofficePost($this, '/backoffice/login', ['email' => $staff->user->email, 'password' => 'password'])->assertRedirect();
    backofficePost($this, '/backoffice/mfa/enrollment', ['token' => 'wrong'])->assertSessionHasErrors('token');
    $response = backofficePost($this, '/backoffice/mfa/enrollment', ['token' => 'private-invitation']);
    $response->assertOk()->assertSee('Add an authenticator account')->assertHeader('Cache-Control', 'no-store, private');
    $secret = $staff->refresh()->mfa_secret;
    expect($secret)->toMatch('/\A[A-Z2-7]{32}\z/');
    expect($staff->enrollment_token_hash)->toBeNull();
    expect(DB::table('staff_memberships')->where('id', $staff->id)->value('mfa_secret'))->not->toBe($secret);
    $this->get('/backoffice/mfa')->assertOk()->assertDontSee($secret);
    backofficePost($this, '/backoffice/mfa/enrollment', ['token' => 'private-invitation'])->assertSessionHasErrors('token');
    $staff->mfa_secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
    $staff->save();
    backofficePost($this, '/backoffice/mfa', ['code' => '279037'])->assertRedirect(route('backoffice.dashboard'));
    expect($staff->refresh()->mfa_confirmed_at)->not->toBeNull();
    backofficePost($this, '/backoffice/mfa/enrollment', ['token' => 'private-invitation'])->assertSessionHasErrors('token');
});

it('does not let an uninvited browser confirm an unenrolled factor', function (): void {
    $this->travelTo(CarbonImmutable::createFromTimestampUTC(2000000000));
    $staff = StaffMembership::factory()->create(['mfa_secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ']);
    backofficePost($this, '/backoffice/login', ['email' => $staff->user->email, 'password' => 'password'])->assertRedirect();
    backofficePost($this, '/backoffice/mfa', ['code' => '279037'])->assertSessionHasErrors('code');
});

it('throttles repeated invalid authenticator attempts', function (): void {
    $this->travelTo(CarbonImmutable::createFromTimestampUTC(2000000000));
    $staff = StaffMembership::factory()->enrolled()->create();
    backofficePost($this, '/backoffice/login', ['email' => $staff->user->email, 'password' => 'password'])->assertRedirect();
    for ($attempt = 0; $attempt < 5; $attempt++) {
        backofficePost($this, '/backoffice/mfa', ['code' => '000000'])->assertSessionHasErrors('code');
    }
    backofficePost($this, '/backoffice/mfa', ['code' => '279037'])->assertSessionHasErrors(['code' => 'Too many attempts. Try again later.']);
});

it('expires MFA and prevents replay after signing out', function (): void {
    $this->travelTo(CarbonImmutable::createFromTimestampUTC(2000000000));
    $staff = signInBackOffice($this);
    backofficePost($this, '/backoffice/logout')->assertRedirect(route('backoffice.login'));
    $this->get('/backoffice')->assertRedirect(route('backoffice.login'));
    backofficePost($this, '/backoffice/login', ['email' => $staff->user->email, 'password' => 'password'])->assertRedirect();
    backofficePost($this, '/backoffice/mfa', ['code' => '279037'])->assertSessionHasErrors('code');
});

it('denies staff whose membership is disabled after MFA', function (): void {
    $this->travelTo(CarbonImmutable::createFromTimestampUTC(2000000000));
    $staff = signInBackOffice($this);
    $staff->update(['enabled' => false]);
    $this->get('/backoffice')->assertForbidden();
});

it('shows only scoped cases and denies cross-scope mutations', function (): void {
    $this->travelTo(CarbonImmutable::createFromTimestampUTC(2000000000));
    signInBackOffice($this, StaffRole::Support, [11], [22]);
    $visible = OperationalCase::factory()->create(['partner_id' => 11, 'customer_id' => 22]);
    $other = OperationalCase::factory()->create(['partner_id' => 12, 'customer_id' => 22]);
    $this->get('/backoffice/cases')->assertOk()->assertSee($visible->reference)->assertDontSee($other->reference);
    backofficePost($this, '/backoffice/cases/'.$other->id, ['state' => 'assigned', 'reason' => 'investigation_required'])->assertNotFound();
    expect($other->refresh()->state)->toBe('open');
});

it('denies unsupported roles and requires elevation for masked audit export', function (): void {
    $this->travelTo(CarbonImmutable::createFromTimestampUTC(2000000000));
    signInBackOffice($this, StaffRole::Support);
    $this->get('/backoffice/audit')->assertForbidden();
    $this->get('/backoffice/audit/export')->assertForbidden();
});

it('exports only approved scope without sensitive audit data', function (): void {
    $this->travelTo(CarbonImmutable::createFromTimestampUTC(2000000000));
    $staff = signInBackOffice($this, StaffRole::Finance, [11], [22])->refresh();
    StaffElevation::factory()->create(['staff_membership_id' => $staff->id, 'session_binding' => $staff->session_binding, 'partner_id' => 11, 'customer_id' => 22]);
    AuditEvent::factory()->create(['action' => 'visible.action', 'reason' => 'review_required', 'partner_id' => 11, 'customer_id' => 22]);
    AuditEvent::factory()->create(['action' => 'hidden.action', 'partner_id' => 12, 'customer_id' => 22]);
    $response = $this->get('/backoffice/audit/export?partner_id=11&customer_id=22')->assertOk();
    expect($response->streamedContent())->toContain('visible.action')->not->toContain('hidden.action', $staff->user->email, 'mfa_secret');
    $this->get('/backoffice/audit/export?partner_id=12&customer_id=22')->assertForbidden();
});

it('rejects extra privileged enrollment fields and escapes case text', function (): void {
    $this->travelTo(CarbonImmutable::createFromTimestampUTC(2000000000));
    $staff = signInBackOffice($this, StaffRole::Support);
    OperationalCase::factory()->create(['reference' => '<script>alert(1)</script>']);
    $this->get('/backoffice/cases')->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    backofficePost($this, '/backoffice/mfa', ['code' => '000000', 'role' => 'operations', 'enabled' => true])->assertSessionHasErrors('code');
    expect($staff->refresh()->role)->toBe(StaffRole::Support);
});

it('returns a safe validation error for an invalid case workflow transition', function (): void {
    $this->travelTo(CarbonImmutable::createFromTimestampUTC(2000000000));
    signInBackOffice($this);
    $case = OperationalCase::factory()->create();

    backofficePost($this, '/backoffice/cases/'.$case->id, ['state' => 'closed', 'reason' => 'review_complete'])
        ->assertRedirect()->assertSessionHasErrors(['state' => 'This case cannot move to the selected state.']);
    expect($case->refresh()->state)->toBe('open');
    expect($case->events()->count())->toBe(0);
});

it('requires case resolution evidence through the staff HTTP form', function (): void {
    $this->travelTo(CarbonImmutable::createFromTimestampUTC(2000000000));
    $staff = signInBackOffice($this);
    $case = OperationalCase::factory()->create(['state' => 'in_progress', 'owner_id' => $staff->user_id]);

    backofficePost($this, '/backoffice/cases/'.$case->id, ['state' => 'resolved', 'reason' => 'review_complete'])
        ->assertRedirect()->assertSessionHasErrors(['evidence_reference' => 'Add an evidence reference before resolving or closing this case.']);
    expect($case->refresh()->state)->toBe('in_progress');
    expect($case->events()->count())->toBe(0);
});

it('rejects an ineligible owner through the staff HTTP form without exposing their details', function (): void {
    $this->travelTo(CarbonImmutable::createFromTimestampUTC(2000000000));
    signInBackOffice($this, StaffRole::Operations, [11], [22]);
    $outside = StaffMembership::factory()->create(['role' => StaffRole::Operations, 'partner_ids' => [12], 'customer_ids' => null]);
    $case = OperationalCase::factory()->create(['partner_id' => 11, 'customer_id' => 22]);

    $response = backofficePost($this, '/backoffice/cases/'.$case->id, ['state' => 'assigned', 'reason' => 'review_required', 'owner_id' => $outside->user_id])
        ->assertRedirect()->assertSessionHasErrors(['owner_id' => 'Choose an eligible owner within this case scope.']);
    expect($response->getContent())->not->toContain($outside->user->email);
    expect($case->refresh())->state->toBe('open')->owner_id->toBeNull();
    expect($case->events()->count())->toBe(0);
});

it('does not flash authenticator codes or invitation tokens after validation failure', function (): void {
    $this->travelTo(CarbonImmutable::createFromTimestampUTC(2000000000));
    $staff = StaffMembership::factory()->enrolled()->create();
    backofficePost($this, '/backoffice/login', ['email' => $staff->user->email, 'password' => 'password'])->assertRedirect();
    backofficePost($this, '/backoffice/mfa', ['code' => '000000'])->assertSessionHasErrors('code')->assertSessionMissing('_old_input.code');
    backofficePost($this, '/backoffice/mfa/enrollment', ['token' => 'private-invalid-invitation'])->assertSessionHasErrors('token')->assertSessionMissing('_old_input.token');
});

it('denies non-manager elevation lookups consistently before revealing target existence', function (): void {
    $this->travelTo(CarbonImmutable::createFromTimestampUTC(2000000000));
    $support = signInBackOffice($this, StaffRole::Support);
    foreach ([$support->id, 999999] as $target) {
        backofficePost($this, '/backoffice/elevations', ['staff_id' => $target, 'minutes' => 5, 'reason' => 'operational_review'])->assertForbidden();
        $this->delete('/backoffice/elevations/'.$target, ['reason' => 'operational_review'])->assertForbidden();
    }
});

it('keeps staff admission unavailable outside local when environment protection is incomplete', function (): void {
    app()->detectEnvironment(fn () => 'staging');
    config(['pull2spend.namespace' => 'staging', 'app.debug' => false, 'session.secure' => false]);
    $this->get('/backoffice/login')->assertServiceUnavailable();
    $this->assertDatabaseCount('staff_memberships', 0);
});

it('requires CSRF verification on staff forms outside the testing bypass', function (): void {
    app()->detectEnvironment(fn () => 'local');
    $this->get('/backoffice/login')->assertOk()->assertSee('name="_token"', false);

    $this->post('/backoffice/login', ['email' => 'staff@example.test', 'password' => 'test-only-password'])->assertStatus(419);
    $this->assertDatabaseCount('audit_events', 0);
});

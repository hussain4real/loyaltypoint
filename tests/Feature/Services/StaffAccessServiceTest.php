<?php

declare(strict_types=1);

use App\Enums\StaffRole;
use App\Models\AuditEvent;
use App\Models\OperationalCase;
use App\Models\StaffElevation;
use App\Models\StaffMembership;
use App\Models\User;
use App\Services\AuditService;
use App\Services\StaffAccessService;
use App\Services\StaffMfaService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['pull2spend.backoffice_enabled' => true, 'pull2spend.staff_mfa_ttl_seconds' => 1800]);
});

/** @return array{StaffAccessService, StaffMembership, Request} */
function verifiedStaffContext(StaffRole $role = StaffRole::Operations, ?array $partners = null, ?array $customers = null): array
{
    $staff = StaffMembership::factory()->enrolled()->create(['role' => $role, 'partner_ids' => $partners, 'customer_ids' => $customers]);
    $request = Request::create('/backoffice');
    $session = app('session.store');
    $session->start();
    $request->setLaravelSession($session);
    $request->setUserResolver(fn (): User => $staff->user);
    $session->put('staff_password_user', $staff->user_id);
    $session->put('staff_password_at', now()->timestamp);
    app(StaffMfaService::class)->verify($staff->user, '279037', $session);

    return [new StaffAccessService($request, app(AuditService::class)), $staff->refresh(), $request];
}

it('grants the role permission matrix and denies every other role action', function (StaffRole $role, array $allowed): void {
    $this->travelTo(CarbonImmutable::createFromTimestampUTC(2000000000));
    [$access, $staff] = verifiedStaffContext($role, [11], [22]);
    foreach (['dashboard.read', 'cases.read', 'cases.manage', 'audit.read', 'elevations.manage'] as $permission) {
        if (in_array($permission, $allowed, true)) {
            expect($access->authorize($staff->user, $permission, 11, 22)->id)->toBe($staff->id);
        } else {
            expect(fn () => $access->authorize($staff->user, $permission, 11, 22))->toThrow(AuthorizationException::class);
        }
    }
    expect(fn () => $access->authorize($staff->user, 'arbitrary.admin', 11, 22))->toThrow(AuthorizationException::class);
})->with([
    'operations' => [StaffRole::Operations, ['dashboard.read', 'cases.read', 'cases.manage', 'audit.read', 'elevations.manage']],
    'compliance' => [StaffRole::Compliance, ['dashboard.read', 'cases.read', 'cases.manage', 'audit.read', 'elevations.manage']],
    'finance' => [StaffRole::Finance, ['dashboard.read', 'cases.read', 'cases.manage', 'audit.read']],
    'support' => [StaffRole::Support, ['dashboard.read', 'cases.read', 'cases.manage']],
    'partner' => [StaffRole::PartnerViewer, ['dashboard.read', 'cases.read']],
]);

it('intersects partner and customer scopes and denies global records to scoped staff', function (?int $partner, ?int $customer): void {
    $this->travelTo(CarbonImmutable::createFromTimestampUTC(2000000000));
    [$access, $staff] = verifiedStaffContext(StaffRole::Operations, [11], [22]);
    expect(fn () => $access->authorize($staff->user, 'cases.manage', $partner, $customer))->toThrow(AuthorizationException::class);
})->with([[12, 22], [11, 23], [null, 22], [11, null], [null, null]]);

it('filters lists by both scopes before returning records', function (): void {
    $this->travelTo(CarbonImmutable::createFromTimestampUTC(2000000000));
    [$access, $staff] = verifiedStaffContext(StaffRole::Support, [11], [22]);
    $visible = OperationalCase::factory()->create(['partner_id' => 11, 'customer_id' => 22]);
    OperationalCase::factory()->create(['partner_id' => 12, 'customer_id' => 22]);
    OperationalCase::factory()->create(['partner_id' => 11, 'customer_id' => 23]);
    OperationalCase::factory()->create(['partner_id' => null, 'customer_id' => null]);
    expect($access->scopeQuery(OperationalCase::query(), $staff->user)->pluck('id')->all())->toBe([$visible->id]);
});

it('treats empty scopes as no access and refuses unscoped partner viewers', function (): void {
    $this->travelTo(CarbonImmutable::createFromTimestampUTC(2000000000));
    [$access, $staff] = verifiedStaffContext(StaffRole::PartnerViewer, [], []);
    OperationalCase::factory()->create();
    expect($access->scopeQuery(OperationalCase::query(), $staff->user)->count())->toBe(0);
    $staff->update(['partner_ids' => null]);
    expect(fn () => $access->authorize($staff->user, 'dashboard.read'))->toThrow(AuthorizationException::class);
});

it('rejects access after MFA expiry session rotation disablement and actor substitution', function (string $condition): void {
    $this->travelTo(CarbonImmutable::createFromTimestampUTC(2000000000));
    [$access, $staff, $request] = verifiedStaffContext();
    $actor = $staff->user;
    match ($condition) {
        'expired' => $this->travel(1800)->seconds(),
        'rotated' => $request->session()->regenerate(),
        'disabled' => $staff->update(['enabled' => false]),
        'different_actor' => $actor = User::factory()->create(),
        'missing_mfa' => $request->session()->forget('staff_mfa_at'),
        'feature_disabled' => config(['pull2spend.backoffice_enabled' => false]),
    };
    expect(fn () => $access->authorize($actor, 'dashboard.read'))->toThrow(AuthorizationException::class);
})->with(['expired', 'rotated', 'disabled', 'different_actor', 'missing_mfa', 'feature_disabled']);

it('requires an independent grant tied to the target session and exact scope', function (): void {
    $this->travelTo(CarbonImmutable::createFromTimestampUTC(2000000000));
    [$access, $staff] = verifiedStaffContext();
    expect(fn () => $access->grantElevation($staff->user, $staff, 'audit.export', 10, 'operational_review'))->toThrow(AuthorizationException::class);
    $reviewer = StaffMembership::factory()->enrolled()->create(['role' => StaffRole::Compliance, 'partner_ids' => null, 'customer_ids' => null]);
    $reviewerRequest = Request::create('/backoffice');
    $reviewerSession = new Store('reviewer', new ArraySessionHandler(120));
    $reviewerSession->start();
    $reviewerRequest->setLaravelSession($reviewerSession);
    $reviewerRequest->setUserResolver(fn (): User => $reviewer->user);
    $reviewerSession->put('staff_password_user', $reviewer->user_id);
    $reviewerSession->put('staff_password_at', now()->timestamp);
    app(StaffMfaService::class)->verify($reviewer->user, '279037', $reviewerSession);
    $reviewerAccess = new StaffAccessService($reviewerRequest, app(AuditService::class));
    $grant = $reviewerAccess->grantElevation($reviewer->user, $staff, 'audit.export', 10, 'operational_review', 11, 22);
    expect($access->authorize($staff->user, 'audit.export', 11, 22)->id)->toBe($staff->id);
    expect(fn () => $access->authorize($staff->user, 'audit.export', 12, 22))->toThrow(AuthorizationException::class);
    expect(fn () => $access->authorize($staff->user, 'audit.export'))->toThrow(AuthorizationException::class);
    $reviewerAccess->revokeElevation($reviewer->user, $grant, 'review_complete');
    expect(fn () => $access->authorize($staff->user, 'audit.export', 11, 22))->toThrow(AuthorizationException::class);
    expect(AuditEvent::query()->where('action', 'staff.elevation_revoked')->count())->toBe(1);
});

it('expires elevation at its exact deadline and rejects oversized grants', function (): void {
    $this->travelTo(CarbonImmutable::createFromTimestampUTC(2000000000));
    [$access, $staff] = verifiedStaffContext();
    $grant = StaffElevation::factory()->create(['staff_membership_id' => $staff->id, 'session_binding' => $staff->session_binding, 'expires_at' => now()->addMinute()]);
    expect($access->authorize($staff->user, 'audit.export')->id)->toBe($staff->id);
    $this->travel(1)->minutes();
    expect(fn () => $access->authorize($staff->user, 'audit.export'))->toThrow(AuthorizationException::class);
    $other = StaffMembership::factory()->enrolled()->create(['role' => StaffRole::Finance, 'partner_ids' => null, 'customer_ids' => null, 'session_binding' => 'target']);
    expect(fn () => $access->grantElevation($staff->user, $other, 'audit.export', 31, 'operational_review'))->toThrow(AuthorizationException::class);
    expect($grant->refresh()->revoked_at)->toBeNull();
});

it('preserves encrypted MFA data and excludes it from model serialization', function (): void {
    $staff = StaffMembership::factory()->enrolled()->create();
    expect(DB::table('staff_memberships')->where('id', $staff->id)->value('mfa_secret'))->not->toContain('GEZDGNBVGY3TQOJQ');
    expect($staff->toArray())->not->toHaveKeys(['mfa_secret', 'last_totp_counter', 'enrollment_token_hash', 'session_binding']);
});

it('verifies the RFC6238 SHA1 vectors with six digit truncation', function (int $timestamp, string $code): void {
    $this->travelTo(CarbonImmutable::createFromTimestampUTC($timestamp));
    $staff = StaffMembership::factory()->enrolled()->create();
    $session = app('session.store');
    $session->start();
    $session->put('staff_password_user', $staff->user_id);
    $session->put('staff_password_at', now()->timestamp);
    app(StaffMfaService::class)->verify($staff->user, $code, $session);
    expect($session->get('staff_mfa_user'))->toBe($staff->user_id);
})->with([[59, '287082'], [1111111109, '081804'], [1111111111, '050471'], [1234567890, '005924'], [2000000000, '279037'], [20000000000, '353130']]);

it('rejects replayed authenticator codes and stale password sessions', function (): void {
    $this->travelTo(CarbonImmutable::createFromTimestampUTC(2000000000));
    [, $staff, $request] = verifiedStaffContext();
    expect(fn () => app(StaffMfaService::class)->verify($staff->user, '279037', $request->session()))->toThrow(ValidationException::class);
    $this->travel(601)->seconds();
    expect(fn () => app(StaffMfaService::class)->verify($staff->user, '000000', $request->session()))->toThrow(ValidationException::class);
});

it('resets a factor only after independent global reviewer verification and invalidates old sessions', function (): void {
    $this->travelTo(CarbonImmutable::createFromTimestampUTC(2000000000));
    [$access, $target] = verifiedStaffContext();
    $reviewer = StaffMembership::factory()->enrolled()->create(['role' => StaffRole::Compliance, 'partner_ids' => null, 'customer_ids' => null]);
    $token = app(StaffMfaService::class)->resetEnrollment($reviewer->user, $target, 'password', '279037');
    expect($token)->toHaveLength(48);
    expect($target->refresh()->mfa_secret)->toBeNull();
    expect($target->enrollment_token_hash)->toBe(hash('sha256', $token));
    expect(fn () => $access->authorize($target->user, 'dashboard.read'))->toThrow(AuthorizationException::class);
    expect(fn () => app(StaffMfaService::class)->resetEnrollment($reviewer->user, $target, 'password', '279037'))->toThrow(ValidationException::class);
});

it('rejects corrupt and undersized stored authenticator secrets', function (string $secret): void {
    $this->travelTo(CarbonImmutable::createFromTimestampUTC(2000000000));
    $staff = StaffMembership::factory()->enrolled()->create(['mfa_secret' => $secret]);
    $session = app('session.store');
    $session->start();
    $session->put('staff_password_user', $staff->user_id);
    $session->put('staff_password_at', now()->timestamp);
    expect(fn () => app(StaffMfaService::class)->verify($staff->user, '279037', $session))->toThrow(ValidationException::class);
    expect($session->has('staff_mfa_at'))->toBeFalse();
})->with(['', 'invalid!', 'GEZDGNBVGY3TQOJQ', str_repeat('A', 31), str_repeat('1', 32)]);

it('denies factor reset by the same person an unprivileged role or bad credentials', function (string $condition): void {
    $this->travelTo(CarbonImmutable::createFromTimestampUTC(2000000000));
    $target = StaffMembership::factory()->enrolled()->create();
    $reviewer = $condition === 'self' ? $target : StaffMembership::factory()->enrolled()->create([
        'role' => $condition === 'role' ? StaffRole::Support : StaffRole::Compliance,
        'partner_ids' => $condition === 'scope' ? [11] : null, 'customer_ids' => null,
    ]);
    expect(fn () => app(StaffMfaService::class)->resetEnrollment($reviewer->user, $target,
        $condition === 'password' ? 'wrong' : 'password', $condition === 'code' ? '000000' : '279037'))->toThrow(ValidationException::class);
    expect($target->refresh()->mfa_confirmed_at)->not->toBeNull();
})->with(['self', 'role', 'scope', 'password', 'code']);

it('provisions scoped staff through the trusted CLI with no implicit global grant', function (): void {
    $user = User::factory()->create();
    $this->artisan('staff:provision', ['email' => $user->email, 'role' => 'partner_viewer', '--partner' => [11]])->assertExitCode(0);
    $staff = StaffMembership::query()->where('user_id', $user->id)->firstOrFail();
    expect($staff->role)->toBe(StaffRole::PartnerViewer);
    expect($staff->partner_ids)->toBe([11]);
    expect($staff->customer_ids)->toBeNull();
    expect($staff->enrollment_token_hash)->toHaveLength(64);
    expect($staff->mfa_secret)->toBeNull();
    expect(AuditEvent::query()->where('action', 'staff.provisioned')->count())->toBe(1);
    $this->artisan('staff:provision', ['email' => $user->email, 'role' => 'operations', '--global' => true])->assertExitCode(1);
    expect($staff->refresh()->role)->toBe(StaffRole::PartnerViewer);
});

it('rejects global partner viewers and provisions default scopes as no access', function (): void {
    $user = User::factory()->create();
    $this->artisan('staff:provision', ['email' => $user->email, 'role' => 'partner_viewer', '--global' => true])->assertExitCode(1);
    $this->artisan('staff:provision', ['email' => $user->email, 'role' => 'support'])->assertExitCode(0);
    $staff = StaffMembership::query()->where('user_id', $user->id)->firstOrFail();
    expect($staff->partner_ids)->toBe([]);
    expect($staff->customer_ids)->toBe([]);
});

it('rejects malformed provisioned scope identifiers instead of coercing them', function (string $scope): void {
    $user = User::factory()->create();
    $this->artisan('staff:provision', ['email' => $user->email, 'role' => 'support', '--partner' => [$scope]])->assertExitCode(1);
    expect(StaffMembership::query()->where('user_id', $user->id)->exists())->toBeFalse();
})->with(['11x', '1e2', '-1', '0']);

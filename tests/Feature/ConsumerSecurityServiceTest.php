<?php

declare(strict_types=1);

use App\Enums\AccountRestriction;
use App\Models\AuditEvent;
use App\Models\ConsumerChallenge;
use App\Models\ConsumerDevice;
use App\Models\ConsumerIdentity;
use App\Models\ConsumerSession;
use App\Models\ConsumerStepUp;
use App\Models\User;
use App\Services\ConsumerIdentityFailure;
use App\Services\ConsumerSecurityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['pull2spend.consumer.enabled' => true, 'pull2spend.consumer.idle_lock_seconds' => 300,
        'pull2spend.consumer.max_attempts' => 3, 'pull2spend.consumer.login_requests_per_minute' => 5,
        'pull2spend.consumer.password_fresh_seconds' => 300, 'pull2spend.consumer.step_up_ttl_seconds' => 300]);
});

/** @return array{ConsumerIdentity, ConsumerSession, string} */
function consumerSecurityLogin(?ConsumerIdentity $identity = null): array
{
    $identity ??= ConsumerIdentity::factory()->contactVerified()->create();
    $service = app(ConsumerSecurityService::class);
    $challenge = $service->startLogin(['kind' => 'email', 'value' => $identity->user->email]);
    $wire = $service->authenticate($challenge->id, 'password', 'password');

    return [$identity, ConsumerSession::query()->where('reference', $wire['reference'])->firstOrFail(), $wire['access_token']];
}

/** @return array{OpenSSLAsymmetricKey, string} */
function consumerSecurityKey(): array
{
    $private = @openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);

    expect($private)->toBeInstanceOf(OpenSSLAsymmetricKey::class);

    return [$private, openssl_pkey_get_details($private)['key']];
}

function consumerSecuritySignature(ConsumerChallenge $challenge, OpenSSLAsymmetricKey $key): string
{
    openssl_sign(base64_decode($challenge->payload['message'], true), $signature, $key, OPENSSL_ALGO_SHA256);

    return base64_encode($signature);
}

it('issues one session with a scoped expiring personal token after verified contact password login', function (): void {
    [$identity, $session, $token] = consumerSecurityLogin();
    $request = Request::create('/api/v2/auth/session');
    $user = $identity->user->withAccessToken(PersonalAccessToken::findToken($token));
    $request->setUserResolver(fn (): User => $user);
    expect(app(ConsumerSecurityService::class)->currentSession($request)->id)->toBe($session->id);
    expect(PersonalAccessToken::findToken($token)->abilities)->toBe(['consumer:identity']);
    expect($session->expires_at)->toBeGreaterThan(now());
    expect(ConsumerSession::count())->toBe(1);
    expect(AuditEvent::where('action', 'consumer.session_started')->count())->toBe(1);
});

it('does not issue a session to unknown contacts or incorrect passwords', function (bool $known): void {
    $identity = ConsumerIdentity::factory()->contactVerified()->create();
    $service = app(ConsumerSecurityService::class);
    $challenge = $service->startLogin(['kind' => 'email', 'value' => $known ? $identity->user->email : 'unknown@example.com']);
    expect(fn () => $service->authenticate($challenge->id, 'password', 'wrong'))->toThrow(ConsumerIdentityFailure::class, 'authentication_failed');
    expect($challenge->refresh()->attempts)->toBe(1);
    expect(ConsumerSession::count())->toBe(0);
})->with([true, false]);

it('commits failed challenge attempts before returning errors and caps later correct proofs', function (): void {
    $identity = ConsumerIdentity::factory()->contactVerified()->create();
    $service = app(ConsumerSecurityService::class);
    $challenge = $service->startLogin(['kind' => 'email', 'value' => $identity->user->email]);
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        expect(fn () => $service->authenticate($challenge->id, 'password', 'wrong'))->toThrow(ConsumerIdentityFailure::class);
        expect($challenge->refresh()->attempts)->toBe($attempt);
    }
    expect(fn () => $service->authenticate($challenge->id, 'password', 'password'))->toThrow(ConsumerIdentityFailure::class, 'challenge_attempts_exceeded');
    expect(ConsumerSession::count())->toBe(0);
});

it('rejects expired reused revoked wrong-purpose and wrong-method challenges', function (string $variant): void {
    $identity = ConsumerIdentity::factory()->contactVerified()->create();
    $service = app(ConsumerSecurityService::class);
    $challenge = $service->startLogin(['kind' => 'email', 'value' => $identity->user->email]);
    match ($variant) {
        'expired' => $challenge->update(['expires_at' => now()]),
        'revoked' => $challenge->update(['revoked_at' => now()]),
        'purpose' => $challenge->update(['purpose' => 'contact_activation']),
        'method' => $challenge->update(['method' => 'device_signature']),
        'reused' => $service->authenticate($challenge->id, 'password', 'password'),
    };
    expect(fn () => $service->authenticate($challenge->id, 'password', 'password'))->toThrow(ConsumerIdentityFailure::class);
    expect(ConsumerSession::count())->toBe($variant === 'reused' ? 1 : 0);
})->with(['expired', 'revoked', 'purpose', 'method', 'reused']);

it('requires verified current contact and denies offboarded consumers', function (string $variant): void {
    $identity = ConsumerIdentity::factory()->contactVerified()->create();
    $service = app(ConsumerSecurityService::class);
    $challenge = $service->startLogin(['kind' => 'email', 'value' => $identity->user->email]);
    match ($variant) {
        'unverified' => $identity->update(['contact_verified_at' => null]),
        'changed_contact' => $identity->user->update(['email' => 'changed@example.com']),
        'offboarded' => $identity->update(['restriction' => AccountRestriction::Offboarded]),
    };
    expect(fn () => $service->authenticate($challenge->id, 'password', 'password'))->toThrow(ConsumerIdentityFailure::class);
    expect(ConsumerSession::count())->toBe(0);
})->with(['unverified', 'changed_contact', 'offboarded']);

it('locks at the idle boundary and permits only password fallback before resuming', function (): void {
    $this->freezeTime();
    [, $session] = consumerSecurityLogin();
    $service = app(ConsumerSecurityService::class);
    $this->travel(300)->seconds();
    expect(fn () => $service->assertSession($session))->toThrow(ConsumerIdentityFailure::class, 'session_locked');
    expect($session->refresh()->locked_at)->not->toBeNull();
    expect($service->assertSession($session, true)->locked_at)->not->toBeNull();
    expect(fn () => $service->unlockWithPassword($session, 'wrong'))->toThrow(ConsumerIdentityFailure::class, 'authentication_failed');
    $service->unlockWithPassword($session, 'password');
    expect($service->assertSession($session)->locked_at)->toBeNull();
});

it('rejects revoked expired missing-token changed-contact or wrong-device sessions', function (string $variant): void {
    [$identity, $session] = consumerSecurityLogin();
    $service = app(ConsumerSecurityService::class);
    match ($variant) {
        'session_revoked' => $session->update(['revoked_at' => now()]),
        'device_revoked' => $session->device->update(['revoked_at' => now()]),
        'expired' => $session->update(['expires_at' => now()]),
        'token_deleted' => PersonalAccessToken::findOrFail($session->personal_access_token_id)->delete(),
        'token_expired' => PersonalAccessToken::findOrFail($session->personal_access_token_id)->update(['expires_at' => now()]),
        'contact_changed' => $identity->user->update(['email' => 'changed@example.com']),
        'wrong_device' => $session->update(['device_id' => ConsumerDevice::factory()->create()->id]),
    };
    expect(fn () => $service->assertSession($session))->toThrow(ConsumerIdentityFailure::class, 'consumer_session_required');
})->with(['session_revoked', 'device_revoked', 'expired', 'token_deleted', 'token_expired', 'contact_changed', 'wrong_device']);

it('rejects existing legacy tokens without an authoritative V2 session record', function (): void {
    $user = User::factory()->create();
    $token = $user->createToken('legacy', ['*']);
    $request = Request::create('/api/v2/auth/session');
    $request->setUserResolver(fn (): User => $user->withAccessToken($token->accessToken));
    expect(fn () => app(ConsumerSecurityService::class)->currentSession($request))->toThrow(ConsumerIdentityFailure::class, 'consumer_session_required');
});

it('enrolls a P256 key through proof of possession and authenticates exact native signing bytes', function (): void {
    [$identity, $session] = consumerSecurityLogin();
    [$private, $public] = consumerSecurityKey();
    $service = app(ConsumerSecurityService::class);
    $challenge = $service->beginDeviceEnrollment($session, $public);
    $device = $service->enrollDevice($session, $challenge->id, consumerSecuritySignature($challenge, $private));
    expect($device->assurance)->toBe('key_possession');
    expect($device->toArray())->not->toHaveKeys(['public_key', 'key_fingerprint']);
    $login = $service->startLogin(['kind' => 'email', 'value' => $identity->user->email], $device->reference, 'device_signature');
    $wire = $service->authenticate($login->id, 'device_signature', consumerSecuritySignature($login, $private));
    expect($wire['device_reference'])->toBe($device->reference);
    expect($wire['assurance'])->toBe('key_possession');
    expect($wire)->not->toHaveKey('hardware_attested');
    expect(fn () => $service->authenticate($login->id, 'device_signature', consumerSecuritySignature($login, $private)))->toThrow(ConsumerIdentityFailure::class, 'challenge_unavailable');
});

it('refuses a valid signature for another challenge key session or consumer', function (string $variant): void {
    [, $session] = consumerSecurityLogin();
    [$private, $public] = consumerSecurityKey();
    $service = app(ConsumerSecurityService::class);
    $challenge = $service->beginDeviceEnrollment($session, $public);
    $proof = consumerSecuritySignature($challenge, $private);
    if ($variant === 'challenge') {
        $challenge = $service->beginDeviceEnrollment($session, $public);
    }
    if ($variant === 'key') {
        [$different] = consumerSecurityKey();
        $proof = consumerSecuritySignature($challenge, $different);
    }
    if ($variant === 'session' || $variant === 'consumer') {
        [, $session] = consumerSecurityLogin($variant === 'session' ? $session->identity : null);
    }
    expect(fn () => $service->enrollDevice($session, $challenge->id, $proof))->toThrow(ConsumerIdentityFailure::class);
    expect(ConsumerDevice::query()->whereNotNull('public_key')->count())->toBe(0);
})->with(['challenge', 'key', 'session', 'consumer']);

it('rejects malformed RSA and non-P256 device public keys', function (string $variant): void {
    [, $session] = consumerSecurityLogin();
    $private = $variant === 'rsa' ? @openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048])
        : @openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp384r1']);
    expect($private)->toBeInstanceOf(OpenSSLAsymmetricKey::class);
    $public = $variant === 'malformed' ? 'pretend-key' : openssl_pkey_get_details($private)['key'];
    expect(fn () => app(ConsumerSecurityService::class)->beginDeviceEnrollment($session, $public))->toThrow(ConsumerIdentityFailure::class, 'invalid_device_key');
})->with(['rsa', 'p384', 'malformed']);

it('binds step-up to an exact current session device action and expiry', function (): void {
    $this->freezeTime();
    [, $session] = consumerSecurityLogin();
    $service = app(ConsumerSecurityService::class);
    $digest = hash('sha256', 'freeze-card:card-1');
    $challenge = $service->startStepUp($session, $digest);
    $step = $service->verifyStepUp($session, $challenge->id, 'password', 'password');
    expect($step->action_digest)->toBe($digest);
    expect($service->hasStepUp($session, $digest))->toBeTrue();
    expect($service->hasStepUp($session, hash('sha256', 'close-card:card-1')))->toBeFalse();
    [, $otherSession] = consumerSecurityLogin($session->identity);
    expect($service->hasStepUp($otherSession, $digest))->toBeFalse();
    expect(fn () => $service->verifyStepUp($session, $challenge->id, 'password', 'password'))->toThrow(ConsumerIdentityFailure::class);
    config(['pull2spend.consumer.idle_lock_seconds' => 3600]);
    $this->travel(300)->seconds();
    expect($service->hasStepUp($session, $digest))->toBeFalse();
    expect(ConsumerStepUp::count())->toBe(1);
});

it('revokes only owned devices and invalidates all their sessions challenges and step-up', function (): void {
    [, $session] = consumerSecurityLogin();
    $service = app(ConsumerSecurityService::class);
    $device = $session->device;
    $pending = $service->startStepUp($session, hash('sha256', 'action'));
    $other = ConsumerDevice::factory()->create();
    expect(fn () => $service->revokeDevice($session, $other->reference))->toThrow(ConsumerIdentityFailure::class, 'device_not_found');
    $service->revokeDevice($session, $device->reference);
    expect($pending->refresh()->revoked_at)->not->toBeNull();
    expect($session->refresh()->revoked_at)->not->toBeNull();
    expect($session->personal_access_token_id)->toBeNull();
    expect(fn () => $service->assertSession($session))->toThrow(ConsumerIdentityFailure::class);
    expect($other->refresh()->revoked_at)->toBeNull();
});

it('requires recent password proof for device enrollment and denies disabled consumer features', function (): void {
    $this->freezeTime();
    [, $session] = consumerSecurityLogin();
    [, $public] = consumerSecurityKey();
    config(['pull2spend.consumer.idle_lock_seconds' => 3600]);
    $this->travel(300)->seconds();
    expect(fn () => app(ConsumerSecurityService::class)->beginDeviceEnrollment($session, $public))->toThrow(ConsumerIdentityFailure::class, 'fresh_password_required');
    config(['pull2spend.consumer.enabled' => false]);
    expect(fn () => app(ConsumerSecurityService::class)->assertSession($session))->toThrow(ConsumerIdentityFailure::class, 'consumer_identity_disabled');
});

it('invalidates earlier step-up evidence across explicit and idle lock boundaries', function (string $mode): void {
    $this->freezeTime();
    [$identity, $session] = consumerSecurityLogin();
    $service = app(ConsumerSecurityService::class);
    $digest = hash('sha256', 'reveal:owned-card');
    $challenge = $service->startStepUp($session, $digest);
    $evidence = $service->verifyStepUp($session, $challenge->id, 'password', 'password');
    expect($service->hasStepUp($session, $digest))->toBeTrue();
    if ($mode === 'idle') {
        config(['pull2spend.consumer.idle_lock_seconds' => 60, 'pull2spend.consumer.step_up_ttl_seconds' => 600]);
        $evidence->update(['expires_at' => now()->addMinutes(10)]);
        $this->travel(60)->seconds();
        expect(fn () => $service->assertSession($session))->toThrow(ConsumerIdentityFailure::class, 'session_locked');
    } else {
        $service->lock($session);
    }
    $service->unlockWithPassword($session, 'password');
    expect($service->hasStepUp($session, $digest))->toBeFalse();
    expect($evidence->fresh()->revoked_at)->not->toBeNull();
})->with(['explicit', 'idle']);

it('revokes pending session bound challenges when the authentication boundary changes', function (string $purpose): void {
    [$identity, $session] = consumerSecurityLogin();
    $service = app(ConsumerSecurityService::class);
    if ($purpose === 'device_enrollment') {
        [$private, $public] = consumerSecurityKey();
        $challenge = $service->beginDeviceEnrollment($session, $public);
        $proof = consumerSecuritySignature($challenge, $private);
    } else {
        $challenge = $service->startStepUp($session, hash('sha256', 'card:one'));
        $proof = 'password';
    }
    $service->lock($session);
    $service->unlockWithPassword($session, 'password');
    $operation = fn () => $purpose === 'device_enrollment'
        ? $service->enrollDevice($session, $challenge->id, $proof)
        : $service->verifyStepUp($session, $challenge->id, 'password', $proof);
    expect($operation)->toThrow(ConsumerIdentityFailure::class, 'challenge_unavailable');
    expect($challenge->fresh()->revoked_at)->not->toBeNull();
})->with(['device_enrollment', 'step_up']);

it('performs password proof checking for both unknown and known public login challenges', function (bool $known): void {
    $identity = ConsumerIdentity::factory()->contactVerified()->create();
    $service = app(ConsumerSecurityService::class);
    $challenge = $service->startLogin(['kind' => 'email', 'value' => $known ? $identity->user->email : 'unknown@example.invalid']);
    $hasher = Hash::getFacadeRoot();
    Hash::partialMock()->shouldReceive('check')->once()->andReturnUsing(fn (string $proof, string $hash): bool => $hasher->check($proof, $hash));
    Hash::shouldReceive('make')->once()->andReturnUsing(fn (string $value): string => $hasher->make($value));
    expect(fn () => $service->authenticate($challenge->id, 'password', 'wrong-password'))->toThrow(ConsumerIdentityFailure::class, 'authentication_failed');
})->with([false, true]);

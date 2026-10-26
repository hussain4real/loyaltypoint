<?php

declare(strict_types=1);

use App\Models\ConsumerChallenge;
use App\Models\ConsumerDevice;
use App\Models\ConsumerIdentity;
use App\Models\ConsumerSession;
use App\Models\ConsumerStepUp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['pull2spend.consumer.enabled' => true, 'pull2spend.consumer.idle_lock_seconds' => 300]);
});

/** @return array{ConsumerIdentity, string} */
function consumerHttpLogin(TestCase $test): array
{
    $identity = ConsumerIdentity::factory()->contactVerified()->create();
    $challenge = $test->postJson('/api/v2/auth/challenges', ['contact' => ['kind' => 'email', 'value' => $identity->user->email], 'method' => 'password'])
        ->assertCreated()->assertJsonPath('methods', ['password'])->assertJsonPath('next_action', 'complete_challenge')->json('reference');
    $wire = $test->postJson('/api/v2/auth/sessions', ['challenge_reference' => $challenge, 'method' => 'password', 'proof' => 'password'])
        ->assertCreated()->assertJsonPath('assurance', 'password')->assertJsonPath('locked', false)->json();

    return [$identity, $wire['access_token']];
}

/** @param array<string, mixed> $data */
function consumerHttpRequest(TestCase $test, string $method, string $uri, string $token, array $data = []): TestResponse
{
    app('auth')->forgetGuards();

    return $test->withToken($token)->json($method, $uri, $data);
}

it('validates login challenge and proof boundaries without exposing enrollment state', function (): void {
    $this->postJson('/api/v2/auth/challenges', [])->assertUnprocessable()->assertJsonValidationErrors(['contact', 'method']);
    $this->postJson('/api/v2/auth/sessions', [])->assertUnprocessable()->assertJsonValidationErrors(['challenge_reference', 'method', 'proof']);
    $response = $this->postJson('/api/v2/auth/challenges', ['contact' => ['kind' => 'email', 'value' => 'unknown@example.com'], 'method' => 'password'])->assertCreated();
    $response->assertJsonStructure(['reference', 'purpose', 'methods', 'expires_at', 'payload' => ['message'], 'next_action'])
        ->assertJsonMissingPath('data')->assertJsonMissingPath('user_id')->assertJsonMissingPath('contact_hash');
    expect(base64_decode($response->json('payload.message'), true))->toBeString();
});

it('requires authoritative consumer sessions for every protected security endpoint', function (): void {
    $this->getJson('/api/v2/auth/session')->assertUnauthorized();
    $this->getJson('/api/v2/auth/devices')->assertUnauthorized();
    $user = User::factory()->create();
    $legacyToken = $user->createToken('legacy', ['*'])->plainTextToken;
    consumerHttpRequest($this, 'GET', '/api/v2/auth/session', $legacyToken)->assertUnauthorized()->assertJsonPath('error.code', 'consumer_session_required');
});

it('preserves password proof whitespace and returns only safe session fields', function (): void {
    $identity = ConsumerIdentity::factory()->contactVerified()->create();
    $identity->user->update(['password' => '  secret password  ']);
    $challenge = $this->postJson('/api/v2/auth/challenges', ['contact' => ['kind' => 'email', 'value' => $identity->user->email], 'method' => 'password'])->assertCreated()->json('reference');
    $response = $this->postJson('/api/v2/auth/sessions', ['challenge_reference' => $challenge, 'method' => 'password', 'proof' => '  secret password  '])->assertCreated();
    $response->assertJsonStructure(['access_token', 'reference', 'expires_at', 'device_reference', 'assurance', 'locked'])
        ->assertJsonMissingPath('password')->assertJsonMissingPath('data')->assertHeader('Cache-Control', 'no-store, private');
    consumerHttpRequest($this, 'GET', '/api/v2/auth/session', $response->json('access_token'))->assertOk()->assertJsonMissingPath('access_token');
});

it('locks protected actions while allowing safe status and password unlock', function (): void {
    [, $token] = consumerHttpLogin($this);
    consumerHttpRequest($this, 'POST', '/api/v2/auth/session/lock', $token)->assertNoContent();
    consumerHttpRequest($this, 'GET', '/api/v2/auth/session', $token)->assertOk()->assertJsonPath('locked', true);
    consumerHttpRequest($this, 'GET', '/api/v2/auth/devices', $token)->assertStatus(423)->assertJsonPath('error.code', 'session_locked');
    consumerHttpRequest($this, 'POST', '/api/v2/auth/session/unlock', $token, ['password' => 'wrong'])->assertUnauthorized();
    consumerHttpRequest($this, 'POST', '/api/v2/auth/session/unlock', $token, ['password' => 'password'])->assertOk()->assertJsonPath('locked', false);
    consumerHttpRequest($this, 'GET', '/api/v2/auth/devices', $token)->assertOk();
});

it('expires token sessions and signs out without leaving reusable access', function (): void {
    [, $token] = consumerHttpLogin($this);
    consumerHttpRequest($this, 'POST', '/api/v2/auth/logout', $token)->assertNoContent();
    consumerHttpRequest($this, 'GET', '/api/v2/auth/session', $token)->assertUnauthorized();
    expect(ConsumerSession::query()->sole()->revoked_at)->not->toBeNull();
});

it('enrolls native keys and verifies a signed login through the public wire contract', function (): void {
    [$identity, $token] = consumerHttpLogin($this);
    $private = @openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    expect($private)->toBeInstanceOf(OpenSSLAsymmetricKey::class);
    $public = openssl_pkey_get_details($private)['key'];
    $challenge = consumerHttpRequest($this, 'POST', '/api/v2/auth/devices/challenges', $token, ['public_key' => $public])->assertCreated()->json();
    openssl_sign(base64_decode($challenge['payload']['message'], true), $signature, $private, OPENSSL_ALGO_SHA256);
    $device = consumerHttpRequest($this, 'POST', '/api/v2/auth/devices', $token,
        ['challenge_reference' => $challenge['reference'], 'proof' => base64_encode($signature)])->assertCreated()->assertJsonPath('assurance', 'key_possession')->json('reference');
    $challenge = $this->postJson('/api/v2/auth/challenges', ['contact' => ['kind' => 'email', 'value' => $identity->user->email], 'device_reference' => $device, 'method' => 'device_signature'])->assertCreated()->json();
    openssl_sign(base64_decode($challenge['payload']['message'], true), $signature, $private, OPENSSL_ALGO_SHA256);
    $this->postJson('/api/v2/auth/sessions', ['challenge_reference' => $challenge['reference'], 'method' => 'device_signature', 'proof' => base64_encode($signature)])
        ->assertCreated()->assertJsonPath('device_reference', $device)->assertJsonPath('assurance', 'key_possession');
});

it('denies device references from another consumer without returning their metadata', function (): void {
    [, $token] = consumerHttpLogin($this);
    $other = ConsumerDevice::factory()->create();
    consumerHttpRequest($this, 'GET', '/api/v2/auth/devices', $token)->assertOk()->assertJsonCount(1, 'devices')->assertJsonMissing(['reference' => $other->reference]);
    consumerHttpRequest($this, 'DELETE', '/api/v2/auth/devices/'.$other->reference, $token)->assertNotFound()->assertJsonPath('error.code', 'device_not_found');
    expect($other->refresh()->revoked_at)->toBeNull();
});

it('binds a verified step-up to the requested action and denies replay', function (): void {
    [, $token] = consumerHttpLogin($this);
    $digest = hash('sha256', 'server-action-1');
    $challenge = consumerHttpRequest($this, 'POST', '/api/v2/auth/step-up/challenges', $token, ['action_digest' => $digest, 'method' => 'password'])->assertCreated()->json('reference');
    consumerHttpRequest($this, 'POST', '/api/v2/auth/step-up', $token, ['challenge_reference' => $challenge, 'method' => 'password', 'proof' => 'password'])
        ->assertCreated()->assertJsonPath('action_digest', $digest);
    consumerHttpRequest($this, 'POST', '/api/v2/auth/step-up', $token, ['challenge_reference' => $challenge, 'method' => 'password', 'proof' => 'password'])->assertUnauthorized();
    expect(ConsumerStepUp::count())->toBe(1);
});

it('rejects attempts to change challenge method or submit malformed public keys', function (): void {
    [$identity, $token] = consumerHttpLogin($this);
    consumerHttpRequest($this, 'POST', '/api/v2/auth/devices/challenges', $token, ['public_key' => 'pretend-key'])->assertUnprocessable()->assertJsonPath('error.code', 'invalid_device_key');
    $challenge = $this->postJson('/api/v2/auth/challenges', ['contact' => ['kind' => 'email', 'value' => $identity->user->email], 'method' => 'password'])->assertCreated()->json('reference');
    $this->postJson('/api/v2/auth/sessions', ['challenge_reference' => $challenge, 'method' => 'device_signature', 'proof' => 'password'])->assertUnauthorized();
    expect(ConsumerChallenge::findOrFail($challenge)->attempts)->toBe(1);
});

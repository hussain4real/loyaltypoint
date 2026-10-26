<?php

declare(strict_types=1);

use App\Models\ConsumerIdentity;
use App\Models\ConsumerSession;
use App\Models\Provider;
use App\Models\User;
use App\Models\VendorUserLink;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('returns 403 for locked V2 tokens on every legacy authenticated surface', function (string $uri): void {
    config(['pull2spend.consumer.enabled' => true]);
    $session = ConsumerSession::factory()->create(['locked_at' => now()]);
    $token = User::findOrFail($session->user_id)->createToken('Locked consumer', ['consumer:identity'], now()->addHour());
    $session->update(['personal_access_token_id' => $token->accessToken->id]);

    $this->withToken($token->plainTextToken)->getJson($uri)
        ->assertForbidden()->assertJsonPath('error.code', 'consumer_session_required')->assertJsonMissingPath('user');

    expect($session->refresh()->locked_at)->not->toBeNull();
})->with(['/api/user', '/api/v1/auth/user', '/api/v1/points/balance', '/api/v1/vendor/points/balance']);

it('returns 403 for a V2 identity using a preexisting wildcard legacy token', function (bool $contactVerified): void {
    $identity = ConsumerIdentity::factory()->create(['contact_verified_at' => $contactVerified ? now() : null]);
    $token = $identity->user->createToken('Old legacy token', ['*'])->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/auth/user')
        ->assertForbidden()->assertJsonPath('error.next_action', 'use_v2_session');
})->with(['unverified identity' => false, 'verified identity' => true]);

it('returns 403 for an explicit V2 token even when its identity record is absent', function (): void {
    $user = User::factory()->create();
    $token = $user->createToken('V2 only', ['consumer:identity'])->plainTextToken;

    $this->withToken($token)->getJson('/api/user')->assertForbidden()->assertJsonPath('error.code', 'consumer_session_required');
});

it('returns 422 without issuing legacy password tokens for V2 identities', function (bool $contactVerified): void {
    $identity = ConsumerIdentity::factory()->create(['contact_verified_at' => $contactVerified ? now() : null]);

    $this->postJson('/api/v1/auth/login', ['email' => $identity->user->email, 'password' => 'password', 'device_name' => 'legacy bypass'])
        ->assertUnprocessable()->assertJsonValidationErrors(['email'])->assertJsonPath('errors.email.0', 'The provided credentials are incorrect.');

    $this->assertDatabaseCount('personal_access_tokens', 0);
})->with(['unverified identity' => false, 'verified identity' => true]);

it('returns 422 before consuming a valid vendor OTP or changing links for V2 identities', function (bool $vendorEmailOnly): void {
    $identity = ConsumerIdentity::factory()->contactVerified()->create();
    $provider = Provider::factory()->create();
    $link = VendorUserLink::create(['user_id' => $identity->user_id, 'provider_id' => $provider->id, 'vendor_email' => 'linked-vendor@example.invalid']);
    $otp = app(OtpService::class)->generate($identity->user);
    $input = ['code' => $otp->code, 'device_name' => 'vendor bypass', 'vendor_email' => $link->vendor_email];
    if (! $vendorEmailOnly) {
        $input['email'] = $identity->user->email;
        $input['provider'] = $provider->slug;
    }

    $this->postJson('/api/v1/vendor/auth/verify-otp', $input)->assertUnprocessable()->assertJsonPath('message', 'Authentication failed.');

    $this->assertDatabaseCount('personal_access_tokens', 0);
    $this->assertDatabaseCount('vendor_user_links', 1);
    expect($otp->refresh()->verified_at)->toBeNull()->and($otp->attempts)->toBe(0);
})->with(['linked vendor email' => true, 'platform email' => false]);

it('preserves wildcard legacy tokens and public provider access', function (): void {
    $user = User::factory()->create();
    $token = $user->createToken('Ordinary legacy', ['*'])->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/auth/user')->assertOk()->assertJsonPath('id', $user->id);
    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/user')->assertOk()->assertJsonPath('id', $user->id);
    app('auth')->forgetGuards();
    $this->withToken('invalid-incidental-token')->getJson('/api/v1/providers')->assertOk();
});

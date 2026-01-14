<?php

use App\Mail\OtpMail;
use App\Models\Otp;
use App\Models\Provider;
use App\Models\User;
use App\Models\VendorUserLink;
use App\Services\PointService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

describe('VendorAuthController', function (): void {
    describe('POST /v1/vendor/auth/request-otp', function (): void {
        it('sends OTP to vendor_email when user is already linked to provider', function (): void {
            Mail::fake();

            $user = User::factory()->create(['email' => 'test@example.com']);
            $provider = Provider::factory()->create(['slug' => 'test-provider']);

            // User is already linked to provider
            VendorUserLink::create([
                'user_id' => $user->id,
                'provider_id' => $provider->id,
                'vendor_email' => 'vendor@test.com',
            ]);

            $response = $this->postJson('/api/v1/vendor/auth/request-otp', [
                'email' => 'test@example.com',
                'vendor_name' => 'Test Vendor App',
                'provider' => 'test-provider',
            ]);

            $response->assertOk()
                ->assertJsonStructure(['message', 'expires_in_minutes', 'provider'])
                ->assertJsonPath('provider.slug', 'test-provider');

            // OTP sent to vendor_email for linked users
            Mail::assertSent(OtpMail::class, function ($mail): bool {
                return $mail->hasTo('vendor@test.com');
            });

            expect(Otp::where('user_id', $user->id)->exists())->toBeTrue();
        });

        it('sends OTP to platform email for first-time linking (unlinked user)', function (): void {
            Mail::fake();

            $user = User::factory()->create(['email' => 'test@example.com']);
            $provider = Provider::factory()->create(['slug' => 'test-provider']);

            // User is NOT linked to provider - first-time linking flow
            $response = $this->postJson('/api/v1/vendor/auth/request-otp', [
                'email' => 'test@example.com',
                'vendor_name' => 'Test Vendor App',
                'provider' => 'test-provider',
            ]);

            $response->assertOk()
                ->assertJsonStructure(['message', 'expires_in_minutes', 'provider'])
                ->assertJsonPath('provider.slug', 'test-provider');

            // OTP sent to platform email for first-time users
            Mail::assertSent(OtpMail::class, function ($mail): bool {
                return $mail->hasTo('test@example.com');
            });

            expect(Otp::where('user_id', $user->id)->exists())->toBeTrue();
        });

        it('fails with non-existent email', function (): void {
            $provider = Provider::factory()->create();

            $response = $this->postJson('/api/v1/vendor/auth/request-otp', [
                'email' => 'nonexistent@example.com',
                'vendor_name' => 'Test Vendor App',
                'provider' => $provider->slug,
            ]);

            $response->assertUnprocessable()
                ->assertJsonValidationErrors(['email']);
        });

        it('fails with non-existent provider', function (): void {
            $user = User::factory()->create();

            $response = $this->postJson('/api/v1/vendor/auth/request-otp', [
                'email' => $user->email,
                'vendor_name' => 'Test Vendor App',
                'provider' => 'non-existent-provider',
            ]);

            $response->assertUnprocessable()
                ->assertJsonValidationErrors(['provider']);
        });

        it('fails with inactive provider', function (): void {
            Mail::fake();

            $user = User::factory()->create();
            $provider = Provider::factory()->inactive()->create();

            // No link needed - inactive provider check happens before link check
            $response = $this->postJson('/api/v1/vendor/auth/request-otp', [
                'email' => $user->email,
                'vendor_name' => 'Test Vendor App',
                'provider' => $provider->slug,
            ]);

            $response->assertUnprocessable()
                ->assertJsonPath('message', 'The specified provider is not active.');
        });

        it('requires email or vendor_email, vendor_name, and provider when using email', function (): void {
            $response = $this->postJson('/api/v1/vendor/auth/request-otp', []);

            $response->assertUnprocessable()
                ->assertJsonValidationErrors(['email', 'vendor_name', 'provider']);
        });

        it('does not require provider when using vendor_email', function (): void {
            Mail::fake();

            $user = User::factory()->create();
            $provider = Provider::factory()->create();
            VendorUserLink::create([
                'user_id' => $user->id,
                'provider_id' => $provider->id,
                'vendor_email' => 'vendor@test.com',
            ]);

            $response = $this->postJson('/api/v1/vendor/auth/request-otp', [
                'vendor_email' => 'vendor@test.com',
                'vendor_name' => 'Test Vendor App',
            ]);

            $response->assertOk();
        });

        it('sends OTP to vendor_email when using vendor_email to request', function (): void {
            Mail::fake();

            $user = User::factory()->create(['email' => 'platform@example.com']);
            $provider = Provider::factory()->create(['slug' => 'test-provider']);

            // Create existing link
            VendorUserLink::create([
                'user_id' => $user->id,
                'provider_id' => $provider->id,
                'vendor_email' => 'vendor-customer@vendor.com',
            ]);

            // Request using vendor_email only (no provider needed - derived from link)
            $response = $this->postJson('/api/v1/vendor/auth/request-otp', [
                'vendor_email' => 'vendor-customer@vendor.com',
                'vendor_name' => 'Test Vendor App',
            ]);

            $response->assertOk()
                ->assertJsonPath('provider.slug', 'test-provider');

            // OTP should be sent to vendor_email
            Mail::assertSent(OtpMail::class, function ($mail): bool {
                return $mail->hasTo('vendor-customer@vendor.com');
            });

            expect(Otp::where('user_id', $user->id)->exists())->toBeTrue();
        });

        it('fails when vendor_email is not linked', function (): void {
            Mail::fake();

            $response = $this->postJson('/api/v1/vendor/auth/request-otp', [
                'vendor_email' => 'unknown@vendor.com',
                'vendor_name' => 'Test Vendor App',
            ]);

            $response->assertNotFound()
                ->assertJsonPath('message', 'No account linked to this vendor email.');

            Mail::assertNothingSent();
        });

        it('sends OTP to vendor_email when account is already linked via platform email', function (): void {
            Mail::fake();

            $user = User::factory()->create(['email' => 'platform@example.com']);
            $provider = Provider::factory()->create(['slug' => 'test-provider']);

            // Create existing link
            VendorUserLink::create([
                'user_id' => $user->id,
                'provider_id' => $provider->id,
                'vendor_email' => 'vendor-customer@vendor.com',
            ]);

            $response = $this->postJson('/api/v1/vendor/auth/request-otp', [
                'email' => 'platform@example.com',
                'vendor_name' => 'Test Vendor App',
                'provider' => 'test-provider',
            ]);

            $response->assertOk();

            // OTP should be sent to vendor_email, not platform email
            Mail::assertSent(OtpMail::class, function ($mail): bool {
                return $mail->hasTo('vendor-customer@vendor.com');
            });

            // Should NOT be sent to platform email
            Mail::assertNotSent(OtpMail::class, function ($mail): bool {
                return $mail->hasTo('platform@example.com');
            });
        });

        it('sends OTP to platform email when using platform email and no vendor link exists (first-time linking)', function (): void {
            Mail::fake();

            $user = User::factory()->create(['email' => 'platform@example.com']);
            $provider = Provider::factory()->create(['slug' => 'test-provider']);

            $response = $this->postJson('/api/v1/vendor/auth/request-otp', [
                'email' => 'platform@example.com',
                'vendor_name' => 'Test Vendor App',
                'provider' => 'test-provider',
            ]);

            $response->assertOk();

            // First-time linking sends to platform email
            Mail::assertSent(OtpMail::class, function ($mail): bool {
                return $mail->hasTo('platform@example.com');
            });
        });

        it('sends OTP to correct vendor_email per provider', function (): void {
            Mail::fake();

            $user = User::factory()->create(['email' => 'platform@example.com']);
            $providerA = Provider::factory()->create(['slug' => 'provider-a']);
            $providerB = Provider::factory()->create(['slug' => 'provider-b']);

            // Link different vendor emails per provider
            VendorUserLink::create([
                'user_id' => $user->id,
                'provider_id' => $providerA->id,
                'vendor_email' => 'alice@vendor-a.com',
            ]);

            VendorUserLink::create([
                'user_id' => $user->id,
                'provider_id' => $providerB->id,
                'vendor_email' => 'alice@vendor-b.com',
            ]);

            // Request OTP for provider A
            $this->postJson('/api/v1/vendor/auth/request-otp', [
                'email' => 'platform@example.com',
                'vendor_name' => 'Test Vendor App',
                'provider' => 'provider-a',
            ])->assertOk();

            Mail::assertSent(OtpMail::class, function ($mail): bool {
                return $mail->hasTo('alice@vendor-a.com');
            });
        });
    });

    describe('POST /v1/vendor/auth/verify-otp', function (): void {
        it('verifies valid OTP and returns token with provider balance when linked', function (): void {
            $user = User::factory()->create();
            $provider = Provider::factory()->create(['slug' => 'test-provider']);

            // User must be linked to provider
            VendorUserLink::create([
                'user_id' => $user->id,
                'provider_id' => $provider->id,
                'vendor_email' => 'vendor@test.com',
            ]);

            $otp = Otp::factory()->create([
                'user_id' => $user->id,
                'code' => '123456',
                'purpose' => 'vendor_auth',
                'expires_at' => now()->addMinutes(10),
            ]);

            // Award some points to the user for this provider
            app(PointService::class)->awardPoints($user, $provider, 500, 'Test points');

            $response = $this->postJson('/api/v1/vendor/auth/verify-otp', [
                'email' => $user->email,
                'code' => '123456',
                'device_name' => 'Test Vendor Device',
                'provider' => 'test-provider',
            ]);

            $response->assertOk()
                ->assertJsonStructure([
                    'message',
                    'access_token',
                    'token_type',
                    'user' => ['id', 'name', 'email'],
                    'provider' => ['id', 'name', 'slug'],
                    'points_balance',
                ])
                ->assertJsonPath('provider.slug', 'test-provider')
                ->assertJsonPath('points_balance', 500);

            expect($user->tokens()->count())->toBe(1);
        });

        it('fails verify when user is not linked to provider and no vendor_email provided', function (): void {
            $user = User::factory()->create();
            $provider = Provider::factory()->create();
            Otp::factory()->create([
                'user_id' => $user->id,
                'code' => '123456',
                'purpose' => 'vendor_auth',
            ]);

            $response = $this->postJson('/api/v1/vendor/auth/verify-otp', [
                'email' => $user->email,
                'code' => '123456',
                'device_name' => 'Test Device',
                'provider' => $provider->slug,
            ]);

            $response->assertUnprocessable()
                ->assertJsonPath('requires_linking', true);
        });

        it('returns only specified provider balance, not other providers', function (): void {
            $user = User::factory()->create();
            $providerA = Provider::factory()->named('Provider A')->create();
            $providerB = Provider::factory()->named('Provider B')->create();

            // User must be linked to providerA
            VendorUserLink::create([
                'user_id' => $user->id,
                'provider_id' => $providerA->id,
                'vendor_email' => 'vendor-a@test.com',
            ]);

            // Award points to both providers
            $pointService = app(PointService::class);
            $pointService->awardPoints($user, $providerA, 100, 'Provider A points');
            $pointService->awardPoints($user, $providerB, 500, 'Provider B points');

            Otp::factory()->create([
                'user_id' => $user->id,
                'code' => '123456',
                'purpose' => 'vendor_auth',
            ]);

            $response = $this->postJson('/api/v1/vendor/auth/verify-otp', [
                'email' => $user->email,
                'code' => '123456',
                'device_name' => 'Test Device',
                'provider' => $providerA->slug,
            ]);

            $response->assertOk()
                ->assertJsonPath('provider.slug', $providerA->slug)
                ->assertJsonPath('points_balance', 100);
        });

        it('fails with invalid OTP code when linked', function (): void {
            $user = User::factory()->create();
            $provider = Provider::factory()->create();

            VendorUserLink::create([
                'user_id' => $user->id,
                'provider_id' => $provider->id,
                'vendor_email' => 'vendor@test.com',
            ]);

            Otp::factory()->create([
                'user_id' => $user->id,
                'code' => '123456',
                'purpose' => 'vendor_auth',
            ]);

            $response = $this->postJson('/api/v1/vendor/auth/verify-otp', [
                'email' => $user->email,
                'code' => '000000',
                'device_name' => 'Test Device',
                'provider' => $provider->slug,
            ]);

            $response->assertUnprocessable()
                ->assertJsonPath('message', fn ($message) => str_contains($message, 'Invalid OTP'));
        });

        it('fails with expired OTP when linked', function (): void {
            $user = User::factory()->create();
            $provider = Provider::factory()->create();

            VendorUserLink::create([
                'user_id' => $user->id,
                'provider_id' => $provider->id,
                'vendor_email' => 'vendor@test.com',
            ]);

            Otp::factory()->expired()->create([
                'user_id' => $user->id,
                'code' => '123456',
                'purpose' => 'vendor_auth',
            ]);

            $response = $this->postJson('/api/v1/vendor/auth/verify-otp', [
                'email' => $user->email,
                'code' => '123456',
                'device_name' => 'Test Device',
                'provider' => $provider->slug,
            ]);

            $response->assertUnprocessable()
                ->assertJsonPath('message', fn ($message) => str_contains($message, 'No valid OTP found'));
        });

        it('fails after max attempts exceeded when linked', function (): void {
            $user = User::factory()->create();
            $provider = Provider::factory()->create();

            VendorUserLink::create([
                'user_id' => $user->id,
                'provider_id' => $provider->id,
                'vendor_email' => 'vendor@test.com',
            ]);

            Otp::factory()->maxAttempts()->create([
                'user_id' => $user->id,
                'code' => '123456',
                'purpose' => 'vendor_auth',
            ]);

            $response = $this->postJson('/api/v1/vendor/auth/verify-otp', [
                'email' => $user->email,
                'code' => '123456',
                'device_name' => 'Test Device',
                'provider' => $provider->slug,
            ]);

            $response->assertUnprocessable()
                ->assertJsonPath('message', fn ($message) => str_contains($message, 'Maximum verification attempts exceeded'));
        });

        it('fails with inactive provider when linked', function (): void {
            $user = User::factory()->create();
            $provider = Provider::factory()->inactive()->create();

            VendorUserLink::create([
                'user_id' => $user->id,
                'provider_id' => $provider->id,
                'vendor_email' => 'vendor@test.com',
            ]);

            Otp::factory()->create([
                'user_id' => $user->id,
                'code' => '123456',
                'purpose' => 'vendor_auth',
            ]);

            $response = $this->postJson('/api/v1/vendor/auth/verify-otp', [
                'email' => $user->email,
                'code' => '123456',
                'device_name' => 'Test Device',
                'provider' => $provider->slug,
            ]);

            $response->assertUnprocessable()
                ->assertJsonPath('message', 'The specified provider is not active.');
        });

        it('requires email or vendor_email, code, device_name, and provider when using email', function (): void {
            $response = $this->postJson('/api/v1/vendor/auth/verify-otp', []);

            $response->assertUnprocessable()
                ->assertJsonValidationErrors(['email', 'code', 'device_name', 'provider']);
        });

        it('validates code is exactly 6 characters', function (): void {
            $user = User::factory()->create();
            $provider = Provider::factory()->create();

            $response = $this->postJson('/api/v1/vendor/auth/verify-otp', [
                'email' => $user->email,
                'code' => '12345', // Only 5 characters
                'device_name' => 'Test Device',
                'provider' => $provider->slug,
            ]);

            $response->assertUnprocessable()
                ->assertJsonValidationErrors(['code']);
        });

        it('returns token with read-only abilities when linked', function (): void {
            $user = User::factory()->create();
            $provider = Provider::factory()->create();

            VendorUserLink::create([
                'user_id' => $user->id,
                'provider_id' => $provider->id,
                'vendor_email' => 'vendor@test.com',
            ]);

            Otp::factory()->create([
                'user_id' => $user->id,
                'code' => '123456',
                'purpose' => 'vendor_auth',
            ]);

            $this->postJson('/api/v1/vendor/auth/verify-otp', [
                'email' => $user->email,
                'code' => '123456',
                'device_name' => 'Test Vendor Device',
                'provider' => $provider->slug,
            ]);

            $token = $user->tokens()->first();
            expect($token->abilities)->toContain('points:read')
                ->and($token->abilities)->toContain('transactions:read')
                ->and($token->abilities)->not->toContain('points:award')
                ->and($token->abilities)->not->toContain('points:deduct');
        });

        it('creates vendor user link when vendor_email is provided', function (): void {
            $user = User::factory()->create(['email' => 'alice@example.com']);
            $provider = Provider::factory()->create();
            Otp::factory()->create([
                'user_id' => $user->id,
                'code' => '123456',
                'purpose' => 'vendor_auth',
            ]);

            $response = $this->postJson('/api/v1/vendor/auth/verify-otp', [
                'email' => $user->email,
                'code' => '123456',
                'device_name' => 'Test Vendor Device',
                'provider' => $provider->slug,
                'vendor_email' => 'ali@vendor.com',
            ]);

            $response->assertOk();

            $link = VendorUserLink::where('user_id', $user->id)
                ->where('provider_id', $provider->id)
                ->first();

            expect($link)->not->toBeNull()
                ->and($link->vendor_email)->toBe('ali@vendor.com');
        });

        it('updates existing vendor user link for same user and provider', function (): void {
            $user = User::factory()->create();
            $provider = Provider::factory()->create();

            // Create existing link
            VendorUserLink::create([
                'user_id' => $user->id,
                'provider_id' => $provider->id,
                'vendor_email' => 'old@vendor.com',
            ]);

            Otp::factory()->create([
                'user_id' => $user->id,
                'code' => '123456',
                'purpose' => 'vendor_auth',
            ]);

            $response = $this->postJson('/api/v1/vendor/auth/verify-otp', [
                'email' => $user->email,
                'code' => '123456',
                'device_name' => 'Test Vendor Device',
                'provider' => $provider->slug,
                'vendor_email' => 'new@vendor.com',
            ]);

            $response->assertOk();

            $links = VendorUserLink::where('user_id', $user->id)
                ->where('provider_id', $provider->id)
                ->get();

            expect($links)->toHaveCount(1)
                ->and($links->first()->vendor_email)->toBe('new@vendor.com');
        });

        it('fails if vendor_email is already linked to different user for same provider', function (): void {
            $alice = User::factory()->create(['email' => 'alice@example.com']);
            $bob = User::factory()->create(['email' => 'bob@example.com']);
            $provider = Provider::factory()->create();

            // Alice already linked this vendor email
            VendorUserLink::create([
                'user_id' => $alice->id,
                'provider_id' => $provider->id,
                'vendor_email' => 'ali@vendor.com',
            ]);

            Otp::factory()->create([
                'user_id' => $bob->id,
                'code' => '123456',
                'purpose' => 'vendor_auth',
            ]);

            $response = $this->postJson('/api/v1/vendor/auth/verify-otp', [
                'email' => $bob->email,
                'code' => '123456',
                'device_name' => 'Test Vendor Device',
                'provider' => $provider->slug,
                'vendor_email' => 'ali@vendor.com',
            ]);

            $response->assertUnprocessable()
                ->assertJsonPath('message', 'This vendor email is already linked to another account for this provider.');
        });

        it('allows same vendor_email for different providers', function (): void {
            $alice = User::factory()->create(['email' => 'alice@example.com']);
            $alicia = User::factory()->create(['email' => 'alicia@example.com']);
            $providerA = Provider::factory()->create(['slug' => 'loyalty-plus']);
            $providerB = Provider::factory()->create(['slug' => 'rewards-hub']);

            // Alice links vendor email to providerA
            VendorUserLink::create([
                'user_id' => $alice->id,
                'provider_id' => $providerA->id,
                'vendor_email' => 'ali@vendor.com',
            ]);

            Otp::factory()->create([
                'user_id' => $alicia->id,
                'code' => '123456',
                'purpose' => 'vendor_auth',
            ]);

            // Alicia links same vendor email to providerB - should work
            $response = $this->postJson('/api/v1/vendor/auth/verify-otp', [
                'email' => $alicia->email,
                'code' => '123456',
                'device_name' => 'Test Vendor Device',
                'provider' => $providerB->slug,
                'vendor_email' => 'ali@vendor.com',
            ]);

            $response->assertOk();

            $links = VendorUserLink::where('vendor_email', 'ali@vendor.com')->get();
            expect($links)->toHaveCount(2);
        });

        it('includes vendor_email in response when link is created', function (): void {
            $user = User::factory()->create();
            $provider = Provider::factory()->create();
            Otp::factory()->create([
                'user_id' => $user->id,
                'code' => '123456',
                'purpose' => 'vendor_auth',
            ]);

            $response = $this->postJson('/api/v1/vendor/auth/verify-otp', [
                'email' => $user->email,
                'code' => '123456',
                'device_name' => 'Test Vendor Device',
                'provider' => $provider->slug,
                'vendor_email' => 'ali@vendor.com',
            ]);

            $response->assertOk()
                ->assertJsonPath('vendor_email', 'ali@vendor.com');
        });

        it('fails without vendor_email when not linked (optional field requires linking)', function (): void {
            $user = User::factory()->create();
            $provider = Provider::factory()->create();
            Otp::factory()->create([
                'user_id' => $user->id,
                'code' => '123456',
                'purpose' => 'vendor_auth',
            ]);

            $response = $this->postJson('/api/v1/vendor/auth/verify-otp', [
                'email' => $user->email,
                'code' => '123456',
                'device_name' => 'Test Vendor Device',
                'provider' => $provider->slug,
            ]);

            $response->assertUnprocessable()
                ->assertJsonPath('requires_linking', true);
        });

        it('validates vendor_email format', function (): void {
            $user = User::factory()->create();
            $provider = Provider::factory()->create();
            Otp::factory()->create([
                'user_id' => $user->id,
                'code' => '123456',
                'purpose' => 'vendor_auth',
            ]);

            $response = $this->postJson('/api/v1/vendor/auth/verify-otp', [
                'email' => $user->email,
                'code' => '123456',
                'device_name' => 'Test Vendor Device',
                'provider' => $provider->slug,
                'vendor_email' => 'not-an-email',
            ]);

            $response->assertUnprocessable()
                ->assertJsonValidationErrors(['vendor_email']);
        });

        it('verifies OTP using vendor_email only for linked accounts', function (): void {
            $user = User::factory()->create(['email' => 'platform@example.com']);
            $provider = Provider::factory()->create(['slug' => 'test-provider']);

            // Create existing link
            VendorUserLink::create([
                'user_id' => $user->id,
                'provider_id' => $provider->id,
                'vendor_email' => 'vendor@customer.com',
            ]);

            Otp::factory()->create([
                'user_id' => $user->id,
                'code' => '123456',
                'purpose' => 'vendor_auth',
            ]);

            // Verify using vendor_email only (no platform email, no provider)
            $response = $this->postJson('/api/v1/vendor/auth/verify-otp', [
                'vendor_email' => 'vendor@customer.com',
                'code' => '123456',
                'device_name' => 'Test Vendor Device',
            ]);

            $response->assertOk()
                ->assertJsonPath('user.email', 'platform@example.com')
                ->assertJsonPath('vendor_email', 'vendor@customer.com')
                ->assertJsonPath('provider.slug', 'test-provider');

            expect($user->tokens()->count())->toBe(1);
        });

        it('fails verify with vendor_email when not linked', function (): void {
            $response = $this->postJson('/api/v1/vendor/auth/verify-otp', [
                'vendor_email' => 'unknown@vendor.com',
                'code' => '123456',
                'device_name' => 'Test Vendor Device',
            ]);

            $response->assertNotFound()
                ->assertJsonPath('message', 'No account linked to this vendor email.');
        });

        it('requires provider when using platform email', function (): void {
            $user = User::factory()->create();

            $response = $this->postJson('/api/v1/vendor/auth/verify-otp', [
                'email' => $user->email,
                'code' => '123456',
                'device_name' => 'Test Device',
            ]);

            $response->assertUnprocessable()
                ->assertJsonValidationErrors(['provider']);
        });
    });

    describe('POST /v1/vendor/auth/resend-otp', function (): void {
        it('resends OTP to linked user email with provider', function (): void {
            Mail::fake();

            $user = User::factory()->create();
            $provider = Provider::factory()->create();

            VendorUserLink::create([
                'user_id' => $user->id,
                'provider_id' => $provider->id,
                'vendor_email' => 'vendor@test.com',
            ]);

            $response = $this->postJson('/api/v1/vendor/auth/resend-otp', [
                'email' => $user->email,
                'vendor_name' => 'Test Vendor App',
                'provider' => $provider->slug,
            ]);

            $response->assertOk()
                ->assertJsonPath('message', 'A new verification code has been sent to your email address.')
                ->assertJsonPath('provider.slug', $provider->slug);

            Mail::assertSent(OtpMail::class, function ($mail): bool {
                return $mail->hasTo('vendor@test.com');
            });
        });

        it('resends OTP to platform email when user is not linked to provider (first-time linking)', function (): void {
            Mail::fake();

            $user = User::factory()->create(['email' => 'first-timer@example.com']);
            $provider = Provider::factory()->create();

            $response = $this->postJson('/api/v1/vendor/auth/resend-otp', [
                'email' => $user->email,
                'vendor_name' => 'Test Vendor App',
                'provider' => $provider->slug,
            ]);

            $response->assertOk();

            // First-time linking sends to platform email
            Mail::assertSent(OtpMail::class, function ($mail): bool {
                return $mail->hasTo('first-timer@example.com');
            });
        });

        it('invalidates old OTP when resending for linked user', function (): void {
            Mail::fake();

            $user = User::factory()->create();
            $provider = Provider::factory()->create();

            VendorUserLink::create([
                'user_id' => $user->id,
                'provider_id' => $provider->id,
                'vendor_email' => 'vendor@test.com',
            ]);

            $oldOtp = Otp::factory()->create([
                'user_id' => $user->id,
                'purpose' => 'vendor_auth',
            ]);

            $this->postJson('/api/v1/vendor/auth/resend-otp', [
                'email' => $user->email,
                'vendor_name' => 'Test Vendor App',
                'provider' => $provider->slug,
            ]);

            $oldOtp->refresh();
            expect($oldOtp->isExpired())->toBeTrue();

            $newOtp = Otp::where('user_id', $user->id)
                ->where('id', '!=', $oldOtp->id)
                ->first();

            expect($newOtp)->not->toBeNull()
                ->and($newOtp->isValid())->toBeTrue();
        });

        it('fails resend with inactive provider', function (): void {
            Mail::fake();

            $user = User::factory()->create();
            $provider = Provider::factory()->inactive()->create();

            // No link needed - inactive provider check happens first
            $response = $this->postJson('/api/v1/vendor/auth/resend-otp', [
                'email' => $user->email,
                'vendor_name' => 'Test Vendor App',
                'provider' => $provider->slug,
            ]);

            $response->assertUnprocessable()
                ->assertJsonPath('message', 'The specified provider is not active.');
        });

        it('resends OTP to vendor_email when account is already linked', function (): void {
            Mail::fake();

            $user = User::factory()->create(['email' => 'platform@example.com']);
            $provider = Provider::factory()->create();

            VendorUserLink::create([
                'user_id' => $user->id,
                'provider_id' => $provider->id,
                'vendor_email' => 'vendor-customer@vendor.com',
            ]);

            $response = $this->postJson('/api/v1/vendor/auth/resend-otp', [
                'email' => 'platform@example.com',
                'vendor_name' => 'Test Vendor App',
                'provider' => $provider->slug,
            ]);

            $response->assertOk();

            // OTP should be sent to vendor_email
            Mail::assertSent(OtpMail::class, function ($mail): bool {
                return $mail->hasTo('vendor-customer@vendor.com');
            });

            // Should NOT be sent to platform email
            Mail::assertNotSent(OtpMail::class, function ($mail): bool {
                return $mail->hasTo('platform@example.com');
            });
        });

        it('resends OTP using vendor_email only for linked accounts', function (): void {
            Mail::fake();

            $user = User::factory()->create(['email' => 'platform@example.com']);
            $provider = Provider::factory()->create(['slug' => 'test-provider']);

            VendorUserLink::create([
                'user_id' => $user->id,
                'provider_id' => $provider->id,
                'vendor_email' => 'vendor@customer.com',
            ]);

            // Resend using vendor_email only (no provider needed)
            $response = $this->postJson('/api/v1/vendor/auth/resend-otp', [
                'vendor_email' => 'vendor@customer.com',
                'vendor_name' => 'Test Vendor App',
            ]);

            $response->assertOk()
                ->assertJsonPath('provider.slug', 'test-provider');

            Mail::assertSent(OtpMail::class, function ($mail): bool {
                return $mail->hasTo('vendor@customer.com');
            });

            expect(Otp::where('user_id', $user->id)->exists())->toBeTrue();
        });

        it('fails resend with vendor_email when not linked', function (): void {
            Mail::fake();

            $response = $this->postJson('/api/v1/vendor/auth/resend-otp', [
                'vendor_email' => 'unknown@vendor.com',
                'vendor_name' => 'Test Vendor App',
            ]);

            $response->assertNotFound()
                ->assertJsonPath('message', 'No account linked to this vendor email.');

            Mail::assertNothingSent();
        });
    });
});

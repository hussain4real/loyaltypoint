<?php

use App\Mail\OtpMail;
use App\Models\Otp;
use App\Models\Provider;
use App\Models\User;
use App\Services\PointService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

describe('VendorAuthController', function (): void {
    describe('POST /v1/vendor/auth/request-otp', function (): void {
        it('sends OTP to valid user email with provider', function (): void {
            Mail::fake();

            $user = User::factory()->create(['email' => 'test@example.com']);
            $provider = Provider::factory()->create(['slug' => 'test-provider']);

            $response = $this->postJson('/api/v1/vendor/auth/request-otp', [
                'email' => 'test@example.com',
                'vendor_name' => 'Test Vendor App',
                'provider' => 'test-provider',
            ]);

            $response->assertOk()
                ->assertJsonStructure(['message', 'expires_in_minutes', 'provider'])
                ->assertJsonPath('provider.slug', 'test-provider');

            Mail::assertSent(OtpMail::class, function ($mail) use ($user): bool {
                return $mail->hasTo($user->email);
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

            $response = $this->postJson('/api/v1/vendor/auth/request-otp', [
                'email' => $user->email,
                'vendor_name' => 'Test Vendor App',
                'provider' => $provider->slug,
            ]);

            $response->assertUnprocessable()
                ->assertJsonPath('message', 'The specified provider is not active.');
        });

        it('requires email, vendor_name, and provider', function (): void {
            $response = $this->postJson('/api/v1/vendor/auth/request-otp', []);

            $response->assertUnprocessable()
                ->assertJsonValidationErrors(['email', 'vendor_name', 'provider']);
        });
    });

    describe('POST /v1/vendor/auth/verify-otp', function (): void {
        it('verifies valid OTP and returns token with provider balance', function (): void {
            $user = User::factory()->create();
            $provider = Provider::factory()->create(['slug' => 'test-provider']);
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

        it('returns zero balance for provider with no transactions', function (): void {
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

            $response->assertOk()
                ->assertJsonPath('points_balance', 0);
        });

        it('returns only specified provider balance, not other providers', function (): void {
            $user = User::factory()->create();
            $providerA = Provider::factory()->named('Provider A')->create();
            $providerB = Provider::factory()->named('Provider B')->create();

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

        it('fails with invalid OTP code', function (): void {
            $user = User::factory()->create();
            $provider = Provider::factory()->create();
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

        it('fails with expired OTP', function (): void {
            $user = User::factory()->create();
            $provider = Provider::factory()->create();
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

        it('fails after max attempts exceeded', function (): void {
            $user = User::factory()->create();
            $provider = Provider::factory()->create();
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

        it('fails with inactive provider', function (): void {
            $user = User::factory()->create();
            $provider = Provider::factory()->inactive()->create();
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

        it('requires all fields including provider', function (): void {
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

        it('returns token with read-only abilities', function (): void {
            $user = User::factory()->create();
            $provider = Provider::factory()->create();
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
    });

    describe('POST /v1/vendor/auth/resend-otp', function (): void {
        it('resends OTP to valid user email with provider', function (): void {
            Mail::fake();

            $user = User::factory()->create();
            $provider = Provider::factory()->create();

            $response = $this->postJson('/api/v1/vendor/auth/resend-otp', [
                'email' => $user->email,
                'vendor_name' => 'Test Vendor App',
                'provider' => $provider->slug,
            ]);

            $response->assertOk()
                ->assertJsonPath('message', 'A new verification code has been sent to your email address.')
                ->assertJsonPath('provider.slug', $provider->slug);

            Mail::assertSent(OtpMail::class);
        });

        it('invalidates old OTP when resending', function (): void {
            Mail::fake();

            $user = User::factory()->create();
            $provider = Provider::factory()->create();
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

        it('fails with inactive provider', function (): void {
            Mail::fake();

            $user = User::factory()->create();
            $provider = Provider::factory()->inactive()->create();

            $response = $this->postJson('/api/v1/vendor/auth/resend-otp', [
                'email' => $user->email,
                'vendor_name' => 'Test Vendor App',
                'provider' => $provider->slug,
            ]);

            $response->assertUnprocessable()
                ->assertJsonPath('message', 'The specified provider is not active.');
        });
    });
});

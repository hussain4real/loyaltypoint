<?php

use App\Mail\OtpMail;
use App\Models\Otp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

describe('VendorAuthController', function (): void {
    describe('POST /v1/vendor/auth/request-otp', function (): void {
        it('sends OTP to valid user email', function (): void {
            Mail::fake();

            $user = User::factory()->create(['email' => 'test@example.com']);

            $response = $this->postJson('/api/v1/vendor/auth/request-otp', [
                'email' => 'test@example.com',
                'vendor_name' => 'Test Vendor App',
            ]);

            $response->assertOk()
                ->assertJsonStructure(['message', 'expires_in_minutes']);

            Mail::assertSent(OtpMail::class, function ($mail) use ($user): bool {
                return $mail->hasTo($user->email);
            });

            expect(Otp::where('user_id', $user->id)->exists())->toBeTrue();
        });

        it('fails with non-existent email', function (): void {
            $response = $this->postJson('/api/v1/vendor/auth/request-otp', [
                'email' => 'nonexistent@example.com',
                'vendor_name' => 'Test Vendor App',
            ]);

            $response->assertUnprocessable()
                ->assertJsonValidationErrors(['email']);
        });

        it('requires email and vendor_name', function (): void {
            $response = $this->postJson('/api/v1/vendor/auth/request-otp', []);

            $response->assertUnprocessable()
                ->assertJsonValidationErrors(['email', 'vendor_name']);
        });
    });

    describe('POST /v1/vendor/auth/verify-otp', function (): void {
        it('verifies valid OTP and returns token', function (): void {
            $user = User::factory()->create();
            $otp = Otp::factory()->create([
                'user_id' => $user->id,
                'code' => '123456',
                'purpose' => 'vendor_auth',
                'expires_at' => now()->addMinutes(10),
            ]);

            $response = $this->postJson('/api/v1/vendor/auth/verify-otp', [
                'email' => $user->email,
                'code' => '123456',
                'device_name' => 'Test Vendor Device',
            ]);

            $response->assertOk()
                ->assertJsonStructure([
                    'message',
                    'access_token',
                    'token_type',
                    'user' => ['id', 'name', 'email', 'point_balance', 'loyalty_tier'],
                ]);

            expect($user->tokens()->count())->toBe(1);
        });

        it('fails with invalid OTP code', function (): void {
            $user = User::factory()->create();
            Otp::factory()->create([
                'user_id' => $user->id,
                'code' => '123456',
                'purpose' => 'vendor_auth',
            ]);

            $response = $this->postJson('/api/v1/vendor/auth/verify-otp', [
                'email' => $user->email,
                'code' => '000000',
                'device_name' => 'Test Device',
            ]);

            $response->assertUnprocessable()
                ->assertJsonPath('message', fn ($message) => str_contains($message, 'Invalid OTP'));
        });

        it('fails with expired OTP', function (): void {
            $user = User::factory()->create();
            Otp::factory()->expired()->create([
                'user_id' => $user->id,
                'code' => '123456',
                'purpose' => 'vendor_auth',
            ]);

            $response = $this->postJson('/api/v1/vendor/auth/verify-otp', [
                'email' => $user->email,
                'code' => '123456',
                'device_name' => 'Test Device',
            ]);

            $response->assertUnprocessable()
                ->assertJsonPath('message', fn ($message) => str_contains($message, 'No valid OTP found'));
        });

        it('fails after max attempts exceeded', function (): void {
            $user = User::factory()->create();
            Otp::factory()->maxAttempts()->create([
                'user_id' => $user->id,
                'code' => '123456',
                'purpose' => 'vendor_auth',
            ]);

            $response = $this->postJson('/api/v1/vendor/auth/verify-otp', [
                'email' => $user->email,
                'code' => '123456',
                'device_name' => 'Test Device',
            ]);

            $response->assertUnprocessable()
                ->assertJsonPath('message', fn ($message) => str_contains($message, 'Maximum verification attempts exceeded'));
        });

        it('requires all fields', function (): void {
            $response = $this->postJson('/api/v1/vendor/auth/verify-otp', []);

            $response->assertUnprocessable()
                ->assertJsonValidationErrors(['email', 'code', 'device_name']);
        });

        it('validates code is exactly 6 characters', function (): void {
            $user = User::factory()->create();

            $response = $this->postJson('/api/v1/vendor/auth/verify-otp', [
                'email' => $user->email,
                'code' => '12345', // Only 5 characters
                'device_name' => 'Test Device',
            ]);

            $response->assertUnprocessable()
                ->assertJsonValidationErrors(['code']);
        });

        it('returns token with read-only abilities', function (): void {
            $user = User::factory()->create();
            Otp::factory()->create([
                'user_id' => $user->id,
                'code' => '123456',
                'purpose' => 'vendor_auth',
            ]);

            $this->postJson('/api/v1/vendor/auth/verify-otp', [
                'email' => $user->email,
                'code' => '123456',
                'device_name' => 'Test Vendor Device',
            ]);

            $token = $user->tokens()->first();
            expect($token->abilities)->toContain('points:read')
                ->and($token->abilities)->toContain('transactions:read')
                ->and($token->abilities)->not->toContain('points:award')
                ->and($token->abilities)->not->toContain('points:deduct');
        });
    });

    describe('POST /v1/vendor/auth/resend-otp', function (): void {
        it('resends OTP to valid user email', function (): void {
            Mail::fake();

            $user = User::factory()->create();

            $response = $this->postJson('/api/v1/vendor/auth/resend-otp', [
                'email' => $user->email,
                'vendor_name' => 'Test Vendor App',
            ]);

            $response->assertOk()
                ->assertJsonPath('message', 'A new verification code has been sent to your email address.');

            Mail::assertSent(OtpMail::class);
        });

        it('invalidates old OTP when resending', function (): void {
            Mail::fake();

            $user = User::factory()->create();
            $oldOtp = Otp::factory()->create([
                'user_id' => $user->id,
                'purpose' => 'vendor_auth',
            ]);

            $this->postJson('/api/v1/vendor/auth/resend-otp', [
                'email' => $user->email,
                'vendor_name' => 'Test Vendor App',
            ]);

            $oldOtp->refresh();
            expect($oldOtp->isExpired())->toBeTrue();

            $newOtp = Otp::where('user_id', $user->id)
                ->where('id', '!=', $oldOtp->id)
                ->first();

            expect($newOtp)->not->toBeNull()
                ->and($newOtp->isValid())->toBeTrue();
        });
    });
});

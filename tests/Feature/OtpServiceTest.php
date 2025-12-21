<?php

use App\Mail\OtpMail;
use App\Models\Otp;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->otpService = new OtpService;
});

describe('OtpService', function (): void {
    describe('generate', function (): void {
        it('generates a 6-digit OTP code', function (): void {
            $user = User::factory()->create();

            $otp = $this->otpService->generate($user);

            expect($otp->code)->toHaveLength(6)
                ->and($otp->code)->toMatch('/^\d{6}$/');
        });

        it('creates an OTP with correct attributes', function (): void {
            $user = User::factory()->create();

            $otp = $this->otpService->generate($user, 'vendor_auth');

            expect($otp->user_id)->toBe($user->id)
                ->and($otp->purpose)->toBe('vendor_auth')
                ->and($otp->expires_at)->toBeGreaterThan(now())
                ->and($otp->verified_at)->toBeNull()
                ->and($otp->attempts)->toBe(0);
        });

        it('invalidates existing OTPs when generating a new one', function (): void {
            $user = User::factory()->create();

            $oldOtp = $this->otpService->generate($user);
            $newOtp = $this->otpService->generate($user);

            $oldOtp->refresh();

            expect($oldOtp->isExpired())->toBeTrue()
                ->and($newOtp->isValid())->toBeTrue();
        });
    });

    describe('sendToEmail', function (): void {
        it('sends an OTP email to the user', function (): void {
            Mail::fake();

            $user = User::factory()->create();

            $otp = $this->otpService->sendToEmail($user);

            Mail::assertSent(OtpMail::class, function ($mail) use ($user, $otp): bool {
                return $mail->hasTo($user->email) && $mail->otp->id === $otp->id;
            });
        });
    });

    describe('verify', function (): void {
        it('successfully verifies a valid OTP', function (): void {
            $user = User::factory()->create();
            $otp = $this->otpService->generate($user);

            $result = $this->otpService->verify($user, $otp->code);

            expect($result['success'])->toBeTrue()
                ->and($result['message'])->toBe('OTP verified successfully.')
                ->and($result['otp']->id)->toBe($otp->id);

            $otp->refresh();
            expect($otp->isVerified())->toBeTrue();
        });

        it('fails verification with incorrect code', function (): void {
            $user = User::factory()->create();
            $this->otpService->generate($user);

            $result = $this->otpService->verify($user, '000000');

            expect($result['success'])->toBeFalse()
                ->and($result['message'])->toContain('Invalid OTP');
        });

        it('fails verification when no valid OTP exists', function (): void {
            $user = User::factory()->create();

            $result = $this->otpService->verify($user, '123456');

            expect($result['success'])->toBeFalse()
                ->and($result['message'])->toBe('No valid OTP found. Please request a new one.');
        });

        it('fails verification when OTP is expired', function (): void {
            $user = User::factory()->create();
            $otp = $this->otpService->generate($user);

            // Manually expire the OTP
            $otp->update(['expires_at' => now()->subMinute()]);

            $result = $this->otpService->verify($user, $otp->code);

            expect($result['success'])->toBeFalse()
                ->and($result['message'])->toBe('No valid OTP found. Please request a new one.');
        });

        it('fails verification when OTP is already verified', function (): void {
            $user = User::factory()->create();
            $otp = $this->otpService->generate($user);

            $otp->markAsVerified();

            $result = $this->otpService->verify($user, $otp->code);

            expect($result['success'])->toBeFalse()
                ->and($result['message'])->toBe('No valid OTP found. Please request a new one.');
        });

        it('increments attempts on each verification try', function (): void {
            $user = User::factory()->create();
            $otp = $this->otpService->generate($user);

            $this->otpService->verify($user, 'wrong1');
            $this->otpService->verify($user, 'wrong2');

            $otp->refresh();
            expect($otp->attempts)->toBe(2);
        });

        it('fails after maximum attempts exceeded', function (): void {
            $user = User::factory()->create();
            $otp = $this->otpService->generate($user);

            // Exhaust all attempts
            $this->otpService->verify($user, 'wrong1');
            $this->otpService->verify($user, 'wrong2');
            $this->otpService->verify($user, 'wrong3');

            // Next attempt should fail even with correct code
            $result = $this->otpService->verify($user, $otp->code);

            expect($result['success'])->toBeFalse()
                ->and($result['message'])->toContain('Maximum verification attempts exceeded');
        });
    });

    describe('cleanupExpired', function (): void {
        it('deletes OTPs older than one day', function (): void {
            $user = User::factory()->create();

            // Create old expired OTPs
            Otp::factory()->count(3)->create([
                'user_id' => $user->id,
                'expires_at' => now()->subDays(2),
            ]);

            // Create recent expired OTP (should not be deleted)
            Otp::factory()->create([
                'user_id' => $user->id,
                'expires_at' => now()->subHours(12),
            ]);

            $deleted = $this->otpService->cleanupExpired();

            expect($deleted)->toBe(3)
                ->and(Otp::count())->toBe(1);
        });
    });
});

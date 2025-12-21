<?php

namespace App\Services;

use App\Mail\OtpMail;
use App\Models\Otp;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

class OtpService
{
    /**
     * Default OTP expiration time in minutes.
     */
    protected int $expirationMinutes = 10;

    /**
     * Maximum verification attempts allowed.
     */
    protected int $maxAttempts = 3;

    /**
     * Generate a new OTP for a user.
     */
    public function generate(User $user, string $purpose = 'vendor_auth'): Otp
    {
        // Invalidate any existing valid OTPs for this user and purpose
        $this->invalidateExisting($user, $purpose);

        // Generate a 6-digit OTP code
        $code = $this->generateCode();

        // Create the OTP record
        return Otp::create([
            'user_id' => $user->id,
            'code' => $code,
            'purpose' => $purpose,
            'expires_at' => now()->addMinutes($this->expirationMinutes),
            'attempts' => 0,
        ]);
    }

    /**
     * Send OTP to user's email.
     */
    public function sendToEmail(User $user, string $purpose = 'vendor_auth'): Otp
    {
        $otp = $this->generate($user, $purpose);

        Mail::to($user->email)->send(new OtpMail($otp));

        return $otp;
    }

    /**
     * Verify an OTP code for a user.
     *
     * @return array{success: bool, message: string, otp?: Otp}
     */
    public function verify(User $user, string $code, string $purpose = 'vendor_auth'): array
    {
        $otp = Otp::where('user_id', $user->id)
            ->forPurpose($purpose)
            ->valid()
            ->latest()
            ->first();

        if (! $otp) {
            return [
                'success' => false,
                'message' => 'No valid OTP found. Please request a new one.',
            ];
        }

        // Check if max attempts exceeded
        if ($otp->hasExceededAttempts($this->maxAttempts)) {
            return [
                'success' => false,
                'message' => 'Maximum verification attempts exceeded. Please request a new OTP.',
            ];
        }

        // Increment attempts
        $otp->incrementAttempts();

        // Verify the code
        if ($otp->code !== $code) {
            $remainingAttempts = $this->maxAttempts - $otp->attempts;

            return [
                'success' => false,
                'message' => $remainingAttempts > 0
                    ? "Invalid OTP. {$remainingAttempts} attempt(s) remaining."
                    : 'Invalid OTP. Maximum attempts exceeded. Please request a new OTP.',
            ];
        }

        // Mark as verified
        $otp->markAsVerified();

        return [
            'success' => true,
            'message' => 'OTP verified successfully.',
            'otp' => $otp,
        ];
    }

    /**
     * Generate a random 6-digit OTP code.
     */
    protected function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Invalidate existing valid OTPs for a user and purpose.
     */
    protected function invalidateExisting(User $user, string $purpose): void
    {
        Otp::where('user_id', $user->id)
            ->forPurpose($purpose)
            ->valid()
            ->update(['expires_at' => now()]);
    }

    /**
     * Clean up expired OTPs.
     */
    public function cleanupExpired(): int
    {
        return Otp::where('expires_at', '<', now()->subDay())->delete();
    }
}

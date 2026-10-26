<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StaffRole;
use App\Models\StaffElevation;
use App\Models\StaffMembership;
use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StaffMfaService
{
    public function __construct(private AuditService $audit) {}

    public function beginEnrollment(User $user, string $token, Session $session): string
    {
        $this->requirePasswordSession($user, $session);

        return DB::transaction(function () use ($user, $token, $session): string {
            $staff = StaffMembership::query()->where('user_id', $user->id)->where('enabled', true)->lockForUpdate()->firstOrFail();
            if ($staff->mfa_confirmed_at !== null || ! is_string($staff->enrollment_token_hash)
                || ! hash_equals($staff->enrollment_token_hash, hash('sha256', $token))) {
                throw ValidationException::withMessages(['token' => 'The enrollment token is invalid.']);
            }
            $secret = $this->base32Encode(random_bytes(20));
            $staff->mfa_secret = $secret;
            $staff->enrollment_token_hash = null;
            $staff->save();
            $session->put('staff_enrollment_user', $user->id);
            $session->put('staff_enrollment_binding', hash('sha256', $session->getId()));
            $this->audit->record($user->id, 'staff.mfa_enrollment_started', 'staff:'.$staff->id, 'factor_enrollment');

            return $secret;
        });
    }

    public function verify(User $user, string $code, Session $session): void
    {
        $this->requirePasswordSession($user, $session);
        $rateKey = 'staff-mfa:'.$user->id;
        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            throw ValidationException::withMessages(['code' => 'Too many attempts. Try again later.']);
        }
        RateLimiter::hit($rateKey, 300);
        DB::transaction(function () use ($user, $code, $session): void {
            $staff = StaffMembership::query()->where('user_id', $user->id)->where('enabled', true)->lockForUpdate()->firstOrFail();
            $enrolling = $staff->mfa_confirmed_at === null;
            if ($enrolling && ($session->get('staff_enrollment_user') !== $user->id
                || ! hash_equals(hash('sha256', $session->getId()), (string) $session->get('staff_enrollment_binding')))) {
                throw ValidationException::withMessages(['code' => 'Start factor enrollment with your invitation token.']);
            }
            $counter = $this->matchingCounter($staff->mfa_secret, $code);
            if ($counter === null || ($staff->last_totp_counter !== null && $counter <= $staff->last_totp_counter)) {
                throw ValidationException::withMessages(['code' => 'The authenticator code is invalid or already used.']);
            }
            $staff->last_totp_counter = $counter;
            $staff->mfa_confirmed_at ??= now();
            $session->regenerate();
            $binding = hash('sha256', $session->getId());
            $staff->session_binding = $binding;
            $staff->save();
            $session->put('staff_mfa_user', $user->id);
            $session->put('staff_mfa_at', now()->timestamp);
            $session->put('staff_mfa_binding', $binding);
            $session->forget(['staff_enrollment_user', 'staff_enrollment_binding']);
            $this->audit->record($user->id, 'staff.mfa_verified', 'staff:'.$staff->id, 'factor_verification');
        });
        RateLimiter::clear($rateKey);
    }

    public function requirePasswordSession(User $user, Session $session): void
    {
        $authenticatedAt = $session->get('staff_password_at');
        if ($session->get('staff_password_user') !== $user->id || ! is_int($authenticatedAt)
            || $authenticatedAt > now()->timestamp
            || $authenticatedAt <= now()->timestamp - (int) config('pull2spend.staff_password_ttl_seconds', 600)) {
            throw ValidationException::withMessages(['password' => 'Sign in again before verifying your authenticator.']);
        }
    }

    public function resetEnrollment(User $operator, StaffMembership $target, string $password, string $code): string
    {
        $rateKey = 'staff-reset:'.$operator->id;
        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            throw ValidationException::withMessages(['code' => 'Too many attempts. Try again later.']);
        }
        RateLimiter::hit($rateKey, 300);

        return DB::transaction(function () use ($operator, $target, $password, $code): string {
            $reviewer = StaffMembership::query()->where('user_id', $operator->id)->lockForUpdate()->first();
            $current = StaffMembership::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
            $counter = $reviewer === null ? null : $this->matchingCounter($reviewer->mfa_secret, $code);
            if ($reviewer === null || ! $reviewer->enabled || $reviewer->mfa_confirmed_at === null
                || ! in_array($reviewer->role, [StaffRole::Operations, StaffRole::Compliance], true)
                || $reviewer->partner_ids !== null || $reviewer->customer_ids !== null
                || $operator->id === $current->user_id || ! Hash::check($password, $operator->password)
                || $counter === null || ($reviewer->last_totp_counter !== null && $counter <= $reviewer->last_totp_counter)) {
                throw ValidationException::withMessages(['code' => 'Independent operator verification failed.']);
            }
            $reviewer->last_totp_counter = $counter;
            $reviewer->save();
            $token = Str::random(48);
            $current->mfa_secret = null;
            $current->mfa_confirmed_at = null;
            $current->last_totp_counter = null;
            $current->session_binding = null;
            $current->enrollment_token_hash = hash('sha256', $token);
            $current->save();
            StaffElevation::query()->where('staff_membership_id', $current->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $this->audit->record($operator->id, 'staff.mfa_reset', 'staff:'.$current->id, 'independent_factor_reset');

            return $token;
        });
    }

    private function matchingCounter(?string $secret, string $code): ?int
    {
        if (! is_string($secret) || ! preg_match('/\A[A-Z2-7]{32}\z/', $secret) || ! preg_match('/\A[0-9]{6}\z/', $code)) {
            return null;
        }
        $key = $this->base32Decode($secret);
        if (strlen($key) !== 20) {
            return null;
        }
        $current = intdiv(now()->timestamp, 30);
        foreach ([$current, $current - 1, $current + 1] as $counter) {
            if ($counter < 0) {
                continue;
            }
            $hash = hash_hmac('sha1', pack('N2', intdiv($counter, 4294967296), $counter % 4294967296), $key, true);
            $offset = ord($hash[19]) & 15;
            $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;
            if (hash_equals(str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT), $code)) {
                return $counter;
            }
        }

        return null;
    }

    private function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $result = '';
        foreach (str_split($bits, 5) as $chunk) {
            $result .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
        }

        return $result;
    }

    private function base32Decode(string $secret): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($secret) as $character) {
            $value = strpos($alphabet, $character);
            if ($value === false) {
                return '';
            }
            $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
        }
        $bytes = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr(bindec($chunk));
            }
        }

        return $bytes;
    }
}

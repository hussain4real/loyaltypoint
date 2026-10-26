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
    public function __construct(private AuditService $audit, private StaffTotp $totp) {}

    public function beginEnrollment(User $user, string $token, Session $session): string
    {
        $this->requirePasswordSession($user, $session);

        return DB::transaction(function () use ($user, $token, $session): string {
            $staff = StaffMembership::query()->where('user_id', $user->id)->where('enabled', true)->lockForUpdate()->firstOrFail();
            if ($staff->mfa_confirmed_at !== null || ! is_string($staff->enrollment_token_hash)
                || ! hash_equals($staff->enrollment_token_hash, hash('sha256', $token))) {
                throw ValidationException::withMessages(['token' => 'The enrollment token is invalid.']);
            }
            $secret = $this->totp->createSecret();
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
            $counter = $this->totp->matchingCounter($staff->mfa_secret, $code, now()->toDateTimeImmutable());
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
            $counter = $reviewer === null ? null : $this->totp->matchingCounter($reviewer->mfa_secret, $code, now()->toDateTimeImmutable());
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
}

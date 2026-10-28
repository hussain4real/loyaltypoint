<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AccountRestriction;
use App\Models\ConsumerChallenge;
use App\Models\ConsumerDevice;
use App\Models\ConsumerIdentity;
use App\Models\ConsumerSession;
use App\Models\ConsumerStepUp;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

final class ConsumerSecurityService
{
    public function __construct(private AuditService $audit, private FoundationNotifications $notifications) {}

    public function startLogin(array $contact, ?string $deviceReference = null, string $method = 'password'): ConsumerChallenge
    {
        $this->enabled();
        $this->method($method);
        if (($contact['kind'] ?? null) !== 'email' || ! is_string($contact['value'] ?? null)
            || filter_var($contact['value'], FILTER_VALIDATE_EMAIL) === false) {
            throw new ConsumerIdentityFailure('invalid_contact', 422, 'correct_contact');
        }
        $contactHash = hash('sha256', strtolower(trim($contact['value'])));
        $this->limit('consumer-login:'.$contactHash, (int) config('pull2spend.consumer.login_requests_per_minute', 5));
        $identity = ConsumerIdentity::query()->where('contact_hash', $contactHash)->first();
        $device = $deviceReference === null ? null : ConsumerDevice::query()->where('reference', $deviceReference)
            ->where('consumer_identity_id', $identity?->id)->whereNull('revoked_at')->first();
        if (($deviceReference !== null && $device === null) || ($method === 'device_signature' && $device?->public_key === null)) {
            $identity = null;
        }

        return $this->challenge($identity, 'login', $method, $device?->reference);
    }

    /** @return array<string, bool|string> */
    public function authenticate(string $challengeReference, string $method, string $proof): array
    {
        return $this->consume($challengeReference, 'login', $method, $proof, null,
            function (ConsumerChallenge $challenge, ConsumerIdentity $identity, User $user): array {
                $device = $challenge->device_reference === null ? null : ConsumerDevice::query()
                    ->where('reference', $challenge->device_reference)->where('consumer_identity_id', $identity->id)->whereNull('revoked_at')->first();
                if ($device === null) {
                    $device = ConsumerDevice::create(['reference' => (string) Str::uuid(), 'user_id' => $user->id,
                        'consumer_identity_id' => $identity->id, 'assurance' => 'password']);
                }
                $expiresAt = now()->addSeconds((int) config('pull2spend.consumer.session_ttl_seconds', 43200));
                $token = $user->createToken('Pull2Spend consumer', ['consumer:identity'], $expiresAt);
                $session = ConsumerSession::create(['reference' => (string) Str::uuid(), 'user_id' => $user->id,
                    'consumer_identity_id' => $identity->id, 'device_id' => $device->id, 'personal_access_token_id' => $token->accessToken->id,
                    'auth_method' => $challenge->method, 'authenticated_at' => now(), 'last_active_at' => now(), 'expires_at' => $expiresAt]);
                $this->audit->record($user->id, 'consumer.session_started', $session->reference, 'authentication_verified', customerId: $user->id);
                $this->notifications->enqueue($user->id, 'security_event', $session->reference);

                return ['access_token' => $token->plainTextToken, ...$this->sessionWire($session)];
            });
    }

    public function currentSession(Request $request, bool $allowLocked = false): ConsumerSession
    {
        $this->enabled();
        $user = $request->user();
        $token = $user?->currentAccessToken();
        if (! $user instanceof User || ! $token instanceof PersonalAccessToken || ! $token->can('consumer:identity')) {
            throw new ConsumerIdentityFailure('consumer_session_required', 401, 'sign_in');
        }
        $session = ConsumerSession::query()->where('personal_access_token_id', $token->id)->where('user_id', $user->id)->first();
        if ($session === null) {
            throw new ConsumerIdentityFailure('consumer_session_required', 401, 'sign_in');
        }

        return $this->assertSession($session, $allowLocked);
    }

    public function assertSession(ConsumerSession $session, bool $allowLocked = false): ConsumerSession
    {
        $this->enabled();
        $outcome = DB::transaction(function () use ($session, $allowLocked): array {
            $current = $this->lockedSession($session);
            if ($current->locked_at === null && $current->last_active_at <= now()->subSeconds((int) config('pull2spend.consumer.idle_lock_seconds', 300))) {
                $current->update(['locked_at' => now()]);
                $this->invalidateAssurance($current);
                $this->audit->record($current->user_id, 'consumer.session_locked', $current->reference, 'inactivity_lock', customerId: $current->user_id);
            }
            if ($current->locked_at !== null && ! $allowLocked) {
                return ['failure' => 'session_locked'];
            }
            if ($current->locked_at === null) {
                $current->update(['last_active_at' => now()]);
            }

            return ['value' => $current];
        }, attempts: 5);
        if (isset($outcome['failure'])) {
            throw new ConsumerIdentityFailure('session_locked', 423, 'unlock_session');
        }

        return $outcome['value'];
    }

    public function beginDeviceEnrollment(ConsumerSession $session, string $publicKey): ConsumerChallenge
    {
        $session = $this->assertSession($session);
        $this->freshPassword($session);
        $key = $this->publicKey($publicKey);

        return $this->challenge($session->identity, 'device_enrollment', 'device_signature', $session->device->reference, $session->reference, null, ['public_key' => $key]);
    }

    public function enrollDevice(ConsumerSession $session, string $challengeReference, string $proof): ConsumerDevice
    {
        $session = $this->assertSession($session);
        $this->freshPassword($session);

        return $this->consume($challengeReference, 'device_enrollment', 'device_signature', $proof, $session,
            function (ConsumerChallenge $challenge, ConsumerIdentity $identity, User $user) use ($session): ConsumerDevice {
                $current = $this->lockedSession($session);
                $this->freshPassword($current);
                if ($current->locked_at !== null) {
                    throw new ConsumerIdentityFailure('session_locked', 423, 'unlock_session');
                }
                $key = $this->publicKey($challenge->payload['public_key']);
                $device = $current->device()->lockForUpdate()->firstOrFail();
                if ($device->public_key !== null || ConsumerDevice::query()->where('key_fingerprint', hash('sha256', $key))->exists()) {
                    throw new ConsumerIdentityFailure('device_key_conflict', 409, 'review_devices');
                }
                $device->update(['public_key' => $key, 'key_fingerprint' => hash('sha256', $key), 'assurance' => 'key_possession']);
                $this->audit->record($user->id, 'consumer.device_enrolled', $device->reference, 'key_possession_verified', customerId: $user->id);
                $this->notifications->enqueue($user->id, 'security_event', $device->reference);

                return $device;
            });
    }

    public function lock(ConsumerSession $session): void
    {
        $this->enabled();
        DB::transaction(function () use ($session): void {
            $current = $this->lockedSession($session);
            if ($current->locked_at === null) {
                $current->update(['locked_at' => now()]);
                $this->invalidateAssurance($current);
                $this->audit->record($current->user_id, 'consumer.session_locked', $current->reference, 'consumer_lock', customerId: $current->user_id);
            }
        }, attempts: 5);
    }

    public function unlockWithPassword(ConsumerSession $session, string $password): ConsumerSession
    {
        $this->enabled();
        $this->limit('consumer-unlock:'.$session->reference, 5);

        return DB::transaction(function () use ($session, $password): ConsumerSession {
            $current = $this->lockedSession($session);
            if (! Hash::check($password, User::findOrFail($current->user_id)->password)) {
                throw new ConsumerIdentityFailure('authentication_failed', 401, 'retry_authentication');
            }
            $current->update(['locked_at' => null, 'authenticated_at' => now(), 'last_active_at' => now(), 'auth_method' => 'password']);
            $this->invalidateAssurance($current);
            $this->audit->record($current->user_id, 'consumer.session_unlocked', $current->reference, 'password_verified', customerId: $current->user_id);

            return $current;
        }, attempts: 5);
    }

    public function logout(ConsumerSession $session): void
    {
        $this->enabled();
        DB::transaction(function () use ($session): void {
            $current = $this->lockedSession($session);
            $current->update(['revoked_at' => now()]);
            $this->invalidateAssurance($current);
            PersonalAccessToken::query()->whereKey($current->personal_access_token_id)->delete();
            ConsumerChallenge::query()->where('session_reference', $current->reference)->whereNull('consumed_at')->update(['revoked_at' => now()]);
            $this->audit->record($current->user_id, 'consumer.session_revoked', $current->reference, 'consumer_sign_out', customerId: $current->user_id);
        }, attempts: 5);
    }

    public function revokeDevice(ConsumerSession $session, string $reference): void
    {
        $this->enabled();
        DB::transaction(function () use ($session, $reference): void {
            $current = $this->lockedSession($session);
            $this->freshPassword($current);
            if ($current->locked_at !== null) {
                throw new ConsumerIdentityFailure('session_locked', 423, 'unlock_session');
            }
            $device = ConsumerDevice::query()->where('reference', $reference)->where('consumer_identity_id', $current->consumer_identity_id)->lockForUpdate()->first();
            if ($device === null) {
                throw new ConsumerIdentityFailure('device_not_found', 404, 'review_devices');
            }
            $device->update(['revoked_at' => now()]);
            $sessions = ConsumerSession::query()->where('device_id', $device->id)->whereNull('revoked_at')->get();
            ConsumerSession::query()->where('device_id', $device->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            ConsumerStepUp::query()->whereIn('consumer_session_id', $sessions->pluck('id'))->whereNull('revoked_at')->update(['revoked_at' => now()]);
            PersonalAccessToken::query()->whereIn('id', $sessions->pluck('personal_access_token_id')->filter())->delete();
            ConsumerChallenge::query()->where('device_reference', $reference)->whereNull('consumed_at')->update(['revoked_at' => now()]);
            $this->audit->record($current->user_id, 'consumer.device_revoked', $reference, 'consumer_device_revocation', customerId: $current->user_id);
        }, attempts: 5);
    }

    public function startStepUp(ConsumerSession $session, string $actionDigest, string $method = 'password'): ConsumerChallenge
    {
        $session = $this->assertSession($session);
        $this->method($method);
        if (preg_match('/\A[a-f0-9]{64}\z/', $actionDigest) !== 1 || ($method === 'device_signature' && $session->device->public_key === null)) {
            throw new ConsumerIdentityFailure('invalid_step_up', 422, 'correct_action');
        }
        $this->limit('consumer-step-up:'.$session->reference, 5);

        return $this->challenge($session->identity, 'step_up', $method, $session->device->reference, $session->reference, $actionDigest);
    }

    public function verifyStepUp(ConsumerSession $session, string $challengeReference, string $method, string $proof): ConsumerStepUp
    {
        $session = $this->assertSession($session);

        return $this->consume($challengeReference, 'step_up', $method, $proof, $session,
            function (ConsumerChallenge $challenge, ConsumerIdentity $identity, User $user) use ($session): ConsumerStepUp {
                $current = $this->lockedSession($session);
                if ($current->locked_at !== null) {
                    throw new ConsumerIdentityFailure('session_locked', 423, 'unlock_session');
                }
                $stepUp = ConsumerStepUp::create(['reference' => (string) Str::uuid(), 'consumer_session_id' => $current->id,
                    'device_id' => $current->device_id, 'challenge_reference' => $challenge->id, 'action_digest' => $challenge->action_digest,
                    'expires_at' => now()->addSeconds((int) config('pull2spend.consumer.step_up_ttl_seconds', 300))]);
                $this->audit->record($user->id, 'consumer.step_up_verified', $stepUp->reference, 'sensitive_action_verified', customerId: $user->id);

                return $stepUp;
            });
    }

    public function hasStepUp(ConsumerSession $session, string $actionDigest): bool
    {
        $session = $this->assertSession($session);

        return ConsumerStepUp::query()->where('consumer_session_id', $session->id)->where('device_id', $session->device_id)
            ->where('action_digest', $actionDigest)->whereNull('revoked_at')->where('expires_at', '>', now())->exists();
    }

    private function invalidateAssurance(ConsumerSession $session): void
    {
        ConsumerStepUp::query()->where('consumer_session_id', $session->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        ConsumerChallenge::query()->where('session_reference', $session->reference)->whereNull('consumed_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    /** @return array<string, bool|string> */
    public function sessionWire(ConsumerSession $session): array
    {
        return ['reference' => $session->reference, 'expires_at' => $session->expires_at->toIso8601String(),
            'device_reference' => $session->device->reference, 'locked' => $session->locked_at !== null,
            'assurance' => $session->auth_method === 'device_signature' ? 'key_possession' : 'password'];
    }

    /** @param array<string, string> $extra */
    private function challenge(?ConsumerIdentity $identity, string $purpose, string $method, ?string $deviceReference = null, ?string $sessionReference = null, ?string $actionDigest = null, array $extra = []): ConsumerChallenge
    {
        $id = (string) Str::uuid();
        $expires = now()->addSeconds((int) config('pull2spend.consumer.challenge_ttl_seconds', 300));
        $message = base64_encode(json_encode(['protocol' => 'pull2spend-consumer-v2', 'challenge' => $id, 'purpose' => $purpose,
            'device' => $deviceReference, 'session' => $sessionReference, 'action' => $actionDigest, 'expires' => $expires->timestamp,
            'nonce' => base64_encode(random_bytes(32))], JSON_THROW_ON_ERROR));

        return ConsumerChallenge::create(['id' => $id, 'user_id' => $identity?->user_id, 'consumer_identity_id' => $identity?->id,
            'purpose' => $purpose, 'method' => $method, 'contact_hash' => $identity?->contact_hash, 'device_reference' => $deviceReference,
            'session_reference' => $sessionReference, 'action_digest' => $actionDigest, 'payload' => ['message' => $message, ...$extra],
            'expires_at' => $expires, 'attempts' => 0]);
    }

    private function consume(string $reference, string $purpose, string $method, string $proof, ?ConsumerSession $session, Closure $effect): mixed
    {
        $this->enabled();
        $this->method($method);
        $snapshot = ConsumerChallenge::find($reference);
        if ($snapshot === null) {
            throw new ConsumerIdentityFailure('authentication_failed', 401, 'request_challenge');
        }
        $result = DB::transaction(function () use ($snapshot, $purpose, $method, $proof, $session, $effect): array {
            if (DB::connection()->getDriverName() === 'sqlite') {
                /** SQLite ignores FOR UPDATE; acquire its writer lock before taking a read snapshot. */
                DB::table('consumer_challenges')->where('id', $snapshot->id)->update(['attempts' => DB::raw('attempts')]);
            }
            $user = $snapshot->user_id === null ? null : User::query()->whereKey($snapshot->user_id)->lockForUpdate()->first();
            $identity = $snapshot->consumer_identity_id === null ? null : ConsumerIdentity::query()->whereKey($snapshot->consumer_identity_id)->lockForUpdate()->first();
            $challenge = ConsumerChallenge::query()->whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
            $failure = null;
            if ($challenge->consumed_at !== null || $challenge->revoked_at !== null || $challenge->expires_at <= now()) {
                $failure = 'challenge_unavailable';
            } elseif ($challenge->attempts >= (int) config('pull2spend.consumer.max_attempts', 5)) {
                $failure = 'challenge_attempts_exceeded';
            } else {
                $challenge->attempts++;
                $challenge->save();
                $bound = $challenge->purpose === $purpose && $challenge->method === $method
                    && $challenge->session_reference === $session?->reference
                    && $identity !== null && $user !== null && $identity->user_id === $user->id
                    && hash_equals($identity->contact_hash, hash('sha256', strtolower(trim($user->email))))
                    && hash_equals($identity->contact_hash, (string) $challenge->contact_hash);
                if ($bound && $challenge->device_reference !== null) {
                    $bound = ConsumerDevice::query()->where('reference', $challenge->device_reference)
                        ->where('consumer_identity_id', $identity->id)->whereNull('revoked_at')->exists();
                }
                $proofValid = $this->validProof($challenge, $method, $proof, $user);
                if (! $bound || ! $proofValid) {
                    $failure = 'authentication_failed';
                } elseif ($identity->contact_verified_at === null) {
                    $failure = 'contact_verification_required';
                } elseif ($identity->restriction === AccountRestriction::Offboarded) {
                    $failure = 'consumer_restricted';
                }
            }
            if ($failure !== null) {
                if ($user !== null) {
                    $this->audit->record($user->id, 'consumer.authentication_denied', $challenge->id, $failure, customerId: $user->id);
                }

                return ['failure' => $failure];
            }
            $challenge->consumed_at = now();
            $challenge->save();

            return ['value' => $effect($challenge, $identity, $user)];
        }, attempts: 5);
        if (isset($result['failure'])) {
            throw new ConsumerIdentityFailure($result['failure'], 401, 'request_challenge');
        }

        return $result['value'];
    }

    private function validProof(ConsumerChallenge $challenge, string $method, string $proof, ?User $user): bool
    {
        if ($method === 'password') {
            if (strlen($proof) > 1024) {
                return false;
            }
            $dummyHash = Hash::make('Pull2Spend unknown consumer challenge');
            $valid = Hash::check($proof, $user?->password ?? $dummyHash);

            return $user !== null && $valid;
        }
        $device = $challenge->device_reference === null ? null : ConsumerDevice::query()->where('reference', $challenge->device_reference)
            ->where('consumer_identity_id', $challenge->consumer_identity_id)->whereNull('revoked_at')->first();
        $key = $challenge->purpose === 'device_enrollment' ? ($challenge->payload['public_key'] ?? null) : $device?->public_key;
        $signature = base64_decode($proof, true);
        $message = base64_decode($challenge->payload['message'] ?? '', true);

        return $device !== null && is_string($key) && is_string($signature) && strlen($signature) <= 256
            && is_string($message) && $message !== '' && @openssl_verify($message, $signature, $key, OPENSSL_ALGO_SHA256) === 1;
    }

    private function lockedSession(ConsumerSession $snapshot): ConsumerSession
    {
        $identity = ConsumerIdentity::query()->whereKey($snapshot->consumer_identity_id)->lockForUpdate()->first();
        $device = ConsumerDevice::query()->whereKey($snapshot->device_id)->lockForUpdate()->first();
        $session = ConsumerSession::query()->whereKey($snapshot->id)->lockForUpdate()->first();
        $token = $session?->personal_access_token_id === null ? null : PersonalAccessToken::find($session->personal_access_token_id);
        if ($identity === null || $device === null || $session === null || $session->reference !== $snapshot->reference
            || $session->revoked_at !== null || $session->expires_at <= now() || $device->revoked_at !== null
            || $session->device_id !== $device->id || $session->consumer_identity_id !== $identity->id
            || $device->consumer_identity_id !== $identity->id || $device->user_id !== $identity->user_id || $session->user_id !== $identity->user_id
            || $token === null || ! $token->can('consumer:identity') || (int) $token->tokenable_id !== $identity->user_id
            || $token->tokenable_type !== (new User)->getMorphClass() || ($token->expires_at !== null && $token->expires_at <= now())
            || $identity->contact_verified_at === null || $identity->restriction === AccountRestriction::Offboarded
            || ! hash_equals($identity->contact_hash, hash('sha256', strtolower(trim(User::findOrFail($identity->user_id)->email))))) {
            throw new ConsumerIdentityFailure('consumer_session_required', 401, 'sign_in');
        }

        return $session;
    }

    private function publicKey(string $value): string
    {
        if (strlen($value) > 1024 || ! str_starts_with($value, '-----BEGIN PUBLIC KEY-----')) {
            throw new ConsumerIdentityFailure('invalid_device_key', 422, 'create_device_key');
        }
        $key = @openssl_pkey_get_public($value);
        $details = $key === false ? false : openssl_pkey_get_details($key);
        if ($details === false || $details['type'] !== OPENSSL_KEYTYPE_EC || $details['bits'] !== 256
            || ($details['ec']['curve_name'] ?? null) !== 'prime256v1') {
            throw new ConsumerIdentityFailure('invalid_device_key', 422, 'create_device_key');
        }

        return $details['key'];
    }

    private function freshPassword(ConsumerSession $session): void
    {
        if ($session->auth_method !== 'password' || $session->authenticated_at <= now()->subSeconds((int) config('pull2spend.consumer.password_fresh_seconds', 300))) {
            throw new ConsumerIdentityFailure('fresh_password_required', 403, 'confirm_password');
        }
    }

    private function method(string $method): void
    {
        if (! in_array($method, ['password', 'device_signature'], true)) {
            throw new ConsumerIdentityFailure('unsupported_authentication_method', 422, 'choose_supported_method');
        }
    }

    private function limit(string $key, int $max): void
    {
        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw new ConsumerIdentityFailure('authentication_rate_limited', 429, 'retry_later');
        }
        RateLimiter::hit($key, 60);
    }

    private function enabled(): void
    {
        if (! config('pull2spend.consumer.enabled', false)) {
            throw new ConsumerIdentityFailure('consumer_identity_disabled', 503, 'retry_later');
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\ConsumerContactCodeMail;
use App\Models\ConsumerChallenge;
use App\Models\ConsumerIdentity;
use App\Models\ConsumerOtpDelivery;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

final class ConsumerContactService
{
    public function __construct(private AuditService $audit, private FoundationClaims $claims, private CaseService $cases) {}

    /** @param array{display_name:string,contact:array{kind:string,value:string},password:string,password_confirmation:string,terms_version:string,privacy_notice_version:string} $input */
    public function register(array $input): ConsumerChallenge
    {
        $this->requireAdmission();
        $this->assertNotices($input['terms_version'], $input['privacy_notice_version']);
        if ($input['contact']['kind'] !== 'email') {
            throw new ConsumerIdentityFailure('contact_channel_unavailable', 503, 'contact_support');
        }
        $email = mb_strtolower(trim($input['contact']['value']));
        $digest = hash('sha256', $email);
        $this->requestLimit($digest);
        $passwordHash = Hash::make($input['password']);

        return DB::transaction(function () use ($input, $email, $digest, $passwordHash): ConsumerChallenge {
            $this->sqliteWriter(contactHash: $digest);
            $payload = User::query()->whereRaw('LOWER(email) = ?', [$email])->exists() ? null : [
                'email' => $email, 'display_name' => $input['display_name'], 'password_hash' => $passwordHash,
                'terms_version' => $input['terms_version'], 'privacy_notice_version' => $input['privacy_notice_version'],
                'notices_accepted_at' => now()->toIso8601String(),
            ];

            return $this->issue($digest, $payload);
        }, attempts: 5);
    }

    public function verify(string $reference, string $code): ConsumerIdentity
    {
        $this->requireAdmission();
        $result = DB::transaction(function () use ($reference, $code): ConsumerIdentity|ConsumerIdentityFailure {
            $this->lockContactChallenges($reference);
            $challenge = ConsumerChallenge::query()->whereKey($reference)->lockForUpdate()->first();
            $failure = $this->challengeFailure($challenge);
            if ($failure !== null) {
                return $failure;
            }
            $challenge->increment('attempts');
            if ($challenge->proof_hash === null || ! Hash::check($code, $challenge->proof_hash)) {
                return new ConsumerIdentityFailure($challenge->attempts >= $this->maxAttempts() ? 'challenge_attempts_exceeded' : 'challenge_invalid', 422, 'request_new_code');
            }
            $payload = $challenge->payload;
            if (! is_array($payload) || ! isset($payload['email'], $payload['password_hash'])
                || ! hash_equals($challenge->contact_hash, hash('sha256', $payload['email']))) {
                return new ConsumerIdentityFailure('challenge_invalid', 422, 'request_new_code');
            }
            try {
                $this->assertNotices($payload['terms_version'], $payload['privacy_notice_version']);
            } catch (ConsumerIdentityFailure $failure) {
                return $failure;
            }
            if (User::query()->whereRaw('LOWER(email) = ?', [$payload['email']])->exists()) {
                $challenge->update(['revoked_at' => now(), 'payload' => null, 'proof_hash' => null]);

                return new ConsumerIdentityFailure('challenge_invalid', 422, 'sign_in');
            }
            $user = User::query()->createOrFirst(['email' => $payload['email']], [
                'name' => $payload['display_name'], 'password' => $payload['password_hash'],
            ]);
            if (! $user->wasRecentlyCreated) {
                $challenge->update(['revoked_at' => now(), 'payload' => null, 'proof_hash' => null]);

                return new ConsumerIdentityFailure('challenge_invalid', 422, 'sign_in');
            }
            $user->forceFill(['email_verified_at' => now()])->save();
            $identity = ConsumerIdentity::create([
                'user_id' => $user->id, 'contact_hash' => $challenge->contact_hash, 'contact_verified_at' => now(),
                'terms_version' => $payload['terms_version'], 'privacy_notice_version' => $payload['privacy_notice_version'],
                'notices_accepted_at' => $payload['notices_accepted_at'],
            ])->refresh();
            $challenge->update(['user_id' => $user->id, 'consumer_identity_id' => $identity->id, 'consumed_at' => now(), 'payload' => null, 'proof_hash' => null]);
            $otherChallenges = ConsumerChallenge::where('contact_hash', $challenge->contact_hash)->where('purpose', 'contact_verification')->where('id', '!=', $challenge->id)->whereNull('consumed_at')->pluck('id');
            ConsumerChallenge::whereIn('id', $otherChallenges)->update(['revoked_at' => now(), 'payload' => null, 'proof_hash' => null]);
            ConsumerOtpDelivery::whereIn('challenge_id', $otherChallenges)->whereIn('state', ['pending', 'processing'])->update(['state' => 'expired', 'payload' => null, 'lease_token' => null, 'lease_expires_at' => null]);
            ConsumerOtpDelivery::where('challenge_id', $challenge->id)->whereIn('state', ['pending', 'processing'])
                ->update(['state' => 'expired', 'payload' => null, 'lease_token' => null, 'lease_expires_at' => null]);
            $this->audit->record($user->id, 'consumer.contact_verified', $identity->reference, 'contact_proof_verified', customerId: $user->id);

            return $identity;
        }, attempts: 5);
        if ($result instanceof ConsumerIdentityFailure) {
            throw $result;
        }

        return $result;
    }

    public function resend(string $reference): ConsumerChallenge
    {
        $this->requireAdmission();

        return DB::transaction(function () use ($reference): ConsumerChallenge {
            $this->lockContactChallenges($reference);
            $old = ConsumerChallenge::query()->whereKey($reference)->lockForUpdate()->first();
            if ($old === null || $old->purpose !== 'contact_verification' || $old->method !== 'verified_channel_otp'
                || $old->consumed_at !== null || $old->revoked_at !== null || $old->contact_hash === null) {
                throw new ConsumerIdentityFailure('challenge_invalid', 422, 'start_registration');
            }
            $this->requestLimit($old->contact_hash);
            $new = $this->issue($old->contact_hash, $old->payload);
            $old->update(['revoked_at' => now(), 'payload' => null, 'proof_hash' => null]);
            ConsumerOtpDelivery::where('challenge_id', $old->id)->whereIn('state', ['pending', 'processing'])->update(['state' => 'expired', 'payload' => null, 'lease_token' => null, 'lease_expires_at' => null]);

            return $new;
        }, attempts: 5);
    }

    public function deliver(string $deliveryId): void
    {
        $token = $this->claims->acquire(new ConsumerOtpDelivery, $deliveryId);
        if ($token === null) {
            return;
        }
        $delivery = ConsumerOtpDelivery::findOrFail($deliveryId);
        $challenge = ConsumerChallenge::find($delivery->challenge_id);
        if ($delivery->expires_at <= now() || $challenge === null || $challenge->consumed_at !== null || $challenge->revoked_at !== null) {
            ConsumerOtpDelivery::whereKey($deliveryId)->where('lease_token', $token)->update(['state' => 'expired', 'payload' => null, 'lease_token' => null, 'lease_expires_at' => null]);

            return;
        }
        try {
            $this->assertSafeMailer((string) config('mail.default'));
            $payload = $delivery->payload;
            Mail::to($payload['email'])->send(new ConsumerContactCodeMail($payload['code']));
            ConsumerOtpDelivery::whereKey($deliveryId)->where('lease_token', $token)->where('state', 'processing')->update([
                'state' => 'sent', 'payload' => null, 'completed_at' => now(), 'lease_token' => null, 'lease_expires_at' => null, 'last_error_code' => null,
            ]);
        } catch (Throwable) {
            $state = $delivery->attempts >= (int) config('pull2spend.event_attempts', 5) ? 'failed' : 'pending';
            DB::transaction(function () use ($delivery, $token, $state): void {
                if ($this->claims->release($delivery, $token, 'contact_delivery_unavailable', $state) && $state === 'failed') {
                    ConsumerOtpDelivery::whereKey($delivery->id)->where('state', 'failed')->whereNull('lease_token')->update(['payload' => null]);
                    $this->cases->open('notification', $delivery->id);
                }
            });
        }
    }

    /** @param array<string,string>|null $payload */
    private function issue(string $digest, ?array $payload): ConsumerChallenge
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiry = now()->addSeconds(max(60, min(1800, (int) config('pull2spend.consumer.challenge_ttl_seconds', 300))));
        $challenge = ConsumerChallenge::create(['id' => (string) Str::uuid(), 'purpose' => 'contact_verification', 'method' => 'verified_channel_otp',
            'contact_hash' => $digest, 'proof_hash' => Hash::make($code), 'payload' => $payload, 'expires_at' => $expiry, 'attempts' => 0]);
        if ($payload !== null) {
            ConsumerOtpDelivery::create(['id' => (string) Str::uuid(), 'challenge_id' => $challenge->id, 'payload' => ['email' => $payload['email'], 'code' => $code],
                'state' => 'pending', 'attempts' => 0, 'available_at' => now(), 'expires_at' => $expiry]);
        }

        return $challenge;
    }

    private function challengeFailure(?ConsumerChallenge $challenge): ?ConsumerIdentityFailure
    {
        if ($challenge === null || $challenge->purpose !== 'contact_verification' || $challenge->method !== 'verified_channel_otp' || $challenge->revoked_at !== null) {
            return new ConsumerIdentityFailure('challenge_invalid', 422, 'request_new_code');
        }
        if ($challenge->consumed_at !== null) {
            return new ConsumerIdentityFailure('challenge_replayed', 422, 'sign_in');
        }
        if ($challenge->expires_at <= now()) {
            return new ConsumerIdentityFailure('challenge_expired', 422, 'request_new_code');
        }
        if ($challenge->attempts >= $this->maxAttempts()) {
            return new ConsumerIdentityFailure('challenge_attempts_exceeded', 422, 'request_new_code');
        }

        return null;
    }

    private function requestLimit(string $digest): void
    {
        $key = 'consumer-contact:'.$digest;
        if (RateLimiter::tooManyAttempts($key, max(1, (int) config('pull2spend.consumer.registration_requests_per_minute', 5)))) {
            throw new ConsumerIdentityFailure('rate_limited', 429, 'retry_later');
        }
        RateLimiter::hit($key, 60);
    }

    private function lockContactChallenges(string $reference): void
    {
        $this->sqliteWriter(reference: $reference);
        $snapshot = ConsumerChallenge::find($reference);
        if ($snapshot?->contact_hash !== null) {
            ConsumerChallenge::where('contact_hash', $snapshot->contact_hash)->where('purpose', 'contact_verification')
                ->orderBy('id')->lockForUpdate()->get();
        }
    }

    private function sqliteWriter(?string $reference = null, ?string $contactHash = null): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            /** SQLite ignores FOR UPDATE: take its writer lock before a read snapshot. */
            DB::table('consumer_challenges')->when($reference !== null, fn ($query) => $query->where('id', $reference))
                ->when($contactHash !== null, fn ($query) => $query->where('contact_hash', $contactHash))
                ->update(['attempts' => DB::raw('attempts')]);
        }
    }

    /** @param list<string> $visited */
    private function assertSafeMailer(string $name, array $visited = []): void
    {
        if (app()->environment('testing')) {
            return;
        }
        $settings = config('mail.mailers.'.$name);
        if (in_array($name, $visited, true) || ! is_array($settings)) {
            throw new ConsumerIdentityFailure('contact_delivery_unavailable', 503);
        }
        $transport = $settings['transport'] ?? null;
        if (in_array($transport, ['failover', 'roundrobin'], true)) {
            if (! is_array($settings['mailers'] ?? null) || $settings['mailers'] === []) {
                throw new ConsumerIdentityFailure('contact_delivery_unavailable', 503);
            }
            foreach ($settings['mailers'] as $child) {
                if (! is_string($child)) {
                    throw new ConsumerIdentityFailure('contact_delivery_unavailable', 503);
                }
                $this->assertSafeMailer($child, [...$visited, $name]);
            }
        } elseif (! in_array($transport, ['smtp', 'sendmail', 'mailgun', 'ses', 'ses-v2', 'postmark', 'resend'], true)) {
            throw new ConsumerIdentityFailure('contact_delivery_unavailable', 503);
        }
    }

    private function assertNotices(string $terms, string $privacy): void
    {
        $expectedTerms = config('pull2spend.consumer.terms_version');
        $expectedPrivacy = config('pull2spend.consumer.privacy_notice_version');
        if (! is_string($expectedTerms) || $expectedTerms === '' || ! is_string($expectedPrivacy) || $expectedPrivacy === '') {
            throw new ConsumerIdentityFailure('consent_required', 503, 'contact_support');
        }
        if (! hash_equals($expectedTerms, $terms) || ! hash_equals($expectedPrivacy, $privacy)) {
            throw new ConsumerIdentityFailure('consent_version_changed', 409, 'review_current_notices');
        }
    }

    private function requireAdmission(): void
    {
        if (! config('pull2spend.consumer.enabled', false)) {
            throw new ConsumerIdentityFailure('capability_disabled', 503, 'retry_later');
        }
    }

    private function maxAttempts(): int
    {
        return max(1, min(10, (int) config('pull2spend.consumer.max_attempts', 5)));
    }
}

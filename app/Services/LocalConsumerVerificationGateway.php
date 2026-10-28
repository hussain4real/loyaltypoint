<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\IntegrationStatus;
use JsonException;

/** Explicit local fixture protocol. It neither verifies documents nor calls an actual screening provider. */
final class LocalConsumerVerificationGateway implements ConsumerVerificationGateway
{
    public function source(): string
    {
        return 'local-fixture';
    }

    public function isAvailable(): bool
    {
        return app()->environment('local', 'testing') && config('pull2spend.consumer.verification_mode') === 'fixture';
    }

    public function validateEvidence(string $identityReference, array $evidence): void
    {
        $this->requireAvailable();
        foreach ($evidence as $reference) {
            if (! is_string($reference) || ! str_starts_with($reference, 'fixture:'.$identityReference.'/')) {
                throw new ConsumerIdentityFailure('identity_evidence_not_owned', 404);
            }
        }

    }

    public function submit(string $identityReference, string $revisionReference, array $evidence, string $operationKey): IntegrationResult
    {
        $this->validateEvidence($identityReference, $evidence);

        return new IntegrationResult('fixture:'.hash('sha256', $revisionReference), IntegrationStatus::Pending);
    }

    public function status(string $operationReference): ?ConsumerVerificationDecision
    {
        $this->requireAvailable();

        return null;
    }

    public function verifyCallback(string $body, string $timestamp, string $signature): ConsumerVerificationDecision
    {
        $this->requireAvailable();
        $secret = config('pull2spend.consumer.verification_key');
        if (! is_string($secret) || strlen($secret) < 32 || strlen($body) > (int) config('pull2spend.callback_max_bytes', 16384)
            || preg_match('/\A[0-9]{1,11}\z/', $timestamp) !== 1
            || abs(now()->timestamp - (int) $timestamp) > (int) config('pull2spend.callback_skew_seconds', 300)
            || ! hash_equals(hash_hmac('sha256', $timestamp.'.'.$body, $secret), $signature)) {
            throw new ConsumerIdentityFailure('verification_callback_rejected', 401);
        }
        try {
            $payload = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ConsumerIdentityFailure('invalid_verification_callback', 422);
        }
        if (! is_array($payload)) {
            throw new ConsumerIdentityFailure('invalid_verification_callback', 422);
        }

        return new ConsumerVerificationDecision($this->source(), $payload);
    }

    private function requireAvailable(): void
    {
        if (! $this->isAvailable()) {
            throw new ConsumerIdentityFailure('verification_provider_unavailable', 503);
        }
    }
}

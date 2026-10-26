<?php

declare(strict_types=1);

namespace App\Services;

final class UnavailableConsumerVerificationGateway implements ConsumerVerificationGateway
{
    public function source(): string
    {
        return 'unavailable';
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function validateEvidence(string $identityReference, array $evidence): void
    {
        throw new ConsumerIdentityFailure('verification_provider_unavailable', 503);
    }

    public function submit(string $identityReference, string $revisionReference, array $evidence, string $operationKey): IntegrationResult
    {
        throw new ConsumerIdentityFailure('verification_provider_unavailable', 503);
    }

    public function status(string $operationReference): ?ConsumerVerificationDecision
    {
        throw new ConsumerIdentityFailure('verification_provider_unavailable', 503);
    }

    public function verifyCallback(string $body, string $timestamp, string $signature): ConsumerVerificationDecision
    {
        throw new ConsumerIdentityFailure('verification_provider_unavailable', 503);
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

interface ConsumerVerificationGateway
{
    public function source(): string;

    public function isAvailable(): bool;

    /** @param array{identity_document_reference: string, liveness_evidence_reference: string, identity_details_reference: string} $evidence */
    public function validateEvidence(string $identityReference, array $evidence): void;

    public function submit(string $identityReference, string $revisionReference, array $evidence, string $operationKey): IntegrationResult;

    public function status(string $operationReference): ?ConsumerVerificationDecision;

    public function verifyCallback(string $body, string $timestamp, string $signature): ConsumerVerificationDecision;
}

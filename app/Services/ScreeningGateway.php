<?php

declare(strict_types=1);

namespace App\Services;

/** Evidence references cover KYC/sanctions/PEP/adverse-media review, not raw sensitive payloads. */
interface ScreeningGateway
{
    public function capabilities(): IntegrationCapabilities;

    public function submit(string $userReference, string $identityEvidenceReference, string $operationKey): IntegrationResult;

    public function screeningStatus(string $caseReference): IntegrationResult;

    public function reverify(string $caseReference, string $identityEvidenceReference, string $operationKey): IntegrationResult;

    public function operationStatus(string $operationReference): IntegrationResult;
}

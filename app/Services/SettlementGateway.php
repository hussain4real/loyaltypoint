<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SettlementDirection;

/** File delivery/acknowledgement and actual funding/payment remain separate evidence. */
interface SettlementGateway
{
    public function capabilities(): IntegrationCapabilities;

    public function deliver(string $fileReference, string $checksum, string $currency, SettlementDirection $direction, string $operationKey): IntegrationResult;

    public function acknowledgement(string $fileReference): IntegrationResult;

    public function paymentEvidence(string $settlementReference): IntegrationResult;

    public function operationStatus(string $operationReference): IntegrationResult;
}

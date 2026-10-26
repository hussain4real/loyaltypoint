<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Trusted server adapter. Real implementations authenticate responses/callbacks and declare
 * reserve/debit/status/guaranteed-reversal semantics before redemption can be enabled.
 */
interface LoyaltyConnector
{
    public function capabilities(): IntegrationCapabilities;

    public function authorize(PartnerConnectionContext $connection, string $state, string $operationKey): IntegrationResult;

    public function revoke(PartnerConnectionContext $connection, string $operationKey): IntegrationResult;

    public function readBalance(PartnerConnectionContext $connection): PartnerBalanceSnapshot;

    public function requestOffer(PartnerConnectionContext $connection, int $points, string $operationKey): IntegrationResult;

    /** Confirmation originates from the authenticated point owner, never consumer input. */
    public function confirmTerms(PartnerConnectionContext $connection, PartnerValueOffer $offer, string $operationKey): IntegrationResult;

    public function reserve(PartnerConnectionContext $connection, ConfirmedPartnerOffer $offer, string $operationKey): IntegrationResult;

    public function confirmDebit(PartnerConnectionContext $connection, string $reservationReference, string $operationKey): IntegrationResult;

    public function release(PartnerConnectionContext $connection, string $reservationReference, string $operationKey): IntegrationResult;

    public function reverse(PartnerConnectionContext $connection, string $transactionReference, string $operationKey): IntegrationResult;

    public function operationStatus(PartnerConnectionContext $connection, string $operationReference): IntegrationResult;
}

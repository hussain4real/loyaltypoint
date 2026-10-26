<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DeviceWallet;
use App\Enums\IssuerCardAction;
use DateTimeImmutable;

/** Token/evidence references only. PAN/CVV and raw identity documents are excluded. */
interface IssuerGateway
{
    public function capabilities(): IntegrationCapabilities;

    public function enroll(string $userReference, string $identityEvidenceReference, string $operationKey): IntegrationResult;

    public function issue(string $enrollmentReference, string $operationKey): IntegrationResult;

    public function reveal(string $cardReference, string $stepUpEvidenceReference, string $operationKey): IntegrationResult;

    public function changeCardState(string $cardReference, IssuerCardAction $action, string $operationKey): IntegrationResult;

    public function provision(string $cardReference, DeviceWallet $wallet, string $deviceReference, string $operationKey): IntegrationResult;

    public function fund(string $walletReference, IntegrationMoney $amount, string $fundingEvidenceReference, string $operationKey): IntegrationResult;

    public function balance(string $walletReference): IntegrationResult;

    public function cardEvent(string $eventReference): IntegrationResult;

    public function dispute(string $cardReference, string $transactionReference, string $caseReference, string $operationKey): IntegrationResult;

    public function dailyFeed(DateTimeImmutable $from, DateTimeImmutable $until): IntegrationResult;

    public function operationStatus(string $operationReference): IntegrationResult;
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DeviceWallet;
use App\Enums\IntegrationOperation;
use App\Enums\IssuerCardAction;
use App\Enums\SettlementDirection;
use DateTimeImmutable;
use InvalidArgumentException;

/** Fixture-only issuer/screening/settlement contracts, never provider certification. */
final class SimulatedIntegrationGateway implements IssuerGateway, ScreeningGateway, SettlementGateway
{
    private readonly IntegrationFixtureStore $store;

    /** @param array<string, IntegrationResult> $fixtures Keys are operation-name:subject-reference. */
    public function __construct(string $environment, private readonly IntegrationCapabilities $supported, array $fixtures)
    {
        $this->store = new IntegrationFixtureStore($environment, $fixtures);
    }

    public function capabilities(): IntegrationCapabilities
    {
        return $this->supported;
    }

    public function enroll(string $userReference, string $identityEvidenceReference, string $operationKey): IntegrationResult
    {
        return $this->respond(IntegrationOperation::Enroll, $userReference, ['identity_evidence_reference' => $identityEvidenceReference], $operationKey);
    }

    public function issue(string $enrollmentReference, string $operationKey): IntegrationResult
    {
        return $this->respond(IntegrationOperation::Issue, $enrollmentReference, [], $operationKey);
    }

    public function reveal(string $cardReference, string $stepUpEvidenceReference, string $operationKey): IntegrationResult
    {
        return $this->respond(IntegrationOperation::Reveal, $cardReference, ['step_up_evidence_reference' => $stepUpEvidenceReference], $operationKey);
    }

    public function changeCardState(string $cardReference, IssuerCardAction $action, string $operationKey): IntegrationResult
    {
        return $this->respond(IntegrationOperation::CardState, $cardReference, ['action' => $action->value], $operationKey);
    }

    public function provision(string $cardReference, DeviceWallet $wallet, string $deviceReference, string $operationKey): IntegrationResult
    {
        return $this->respond(IntegrationOperation::Provision, $cardReference, ['wallet' => $wallet->value, 'device_reference' => $deviceReference], $operationKey);
    }

    public function fund(string $walletReference, IntegrationMoney $amount, string $fundingEvidenceReference, string $operationKey): IntegrationResult
    {
        return $this->respond(IntegrationOperation::Fund, $walletReference, ['amount' => $amount->amount, 'currency' => $amount->currency, 'funding_evidence_reference' => $fundingEvidenceReference], $operationKey);
    }

    public function balance(string $walletReference): IntegrationResult
    {
        return $this->respond(IntegrationOperation::IssuerBalance, $walletReference);
    }

    public function cardEvent(string $eventReference): IntegrationResult
    {
        return $this->respond(IntegrationOperation::CardEvent, $eventReference);
    }

    public function dispute(string $cardReference, string $transactionReference, string $caseReference, string $operationKey): IntegrationResult
    {
        return $this->respond(IntegrationOperation::Dispute, $cardReference, ['transaction_reference' => $transactionReference, 'case_reference' => $caseReference], $operationKey);
    }

    public function dailyFeed(DateTimeImmutable $from, DateTimeImmutable $until): IntegrationResult
    {
        if ($until <= $from) {
            throw new InvalidArgumentException('Feed intervals require an exclusive end after the start.');
        }

        return $this->respond(IntegrationOperation::DailyFeed, $from->format('U.u').'/'.$until->format('U.u'));
    }

    public function submit(string $userReference, string $identityEvidenceReference, string $operationKey): IntegrationResult
    {
        return $this->respond(IntegrationOperation::SubmitScreening, $userReference, ['identity_evidence_reference' => $identityEvidenceReference], $operationKey);
    }

    public function screeningStatus(string $caseReference): IntegrationResult
    {
        return $this->respond(IntegrationOperation::ScreeningStatus, $caseReference);
    }

    public function reverify(string $caseReference, string $identityEvidenceReference, string $operationKey): IntegrationResult
    {
        return $this->respond(IntegrationOperation::Reverify, $caseReference, ['identity_evidence_reference' => $identityEvidenceReference], $operationKey);
    }

    public function deliver(string $fileReference, string $checksum, string $currency, SettlementDirection $direction, string $operationKey): IntegrationResult
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $checksum) !== 1 || preg_match('/\A[A-Z]{3}\z/', $currency) !== 1) {
            throw new InvalidArgumentException('Settlement delivery requires SHA-256 membership checksum and currency.');
        }

        return $this->respond(IntegrationOperation::DeliverSettlement, $fileReference, ['checksum' => $checksum, 'currency' => $currency, 'direction' => $direction->value], $operationKey);
    }

    public function acknowledgement(string $fileReference): IntegrationResult
    {
        return $this->respond(IntegrationOperation::SettlementAcknowledgement, $fileReference);
    }

    public function paymentEvidence(string $settlementReference): IntegrationResult
    {
        return $this->respond(IntegrationOperation::PaymentEvidence, $settlementReference);
    }

    public function operationStatus(string $operationReference): IntegrationResult
    {
        $this->supported->require(IntegrationOperation::OperationStatus);

        return $this->store->status('integration', $operationReference);
    }

    /** @param array<string, int|string|null> $terms */
    private function respond(IntegrationOperation $operation, string $subject, array $terms = [], ?string $operationKey = null): IntegrationResult
    {
        $this->supported->require($operation);

        foreach ($terms as $term) {
            if (is_string($term) && trim($term) === '') {
                throw new InvalidArgumentException('Supplied integration references cannot be blank.');
            }
        }

        return $this->store->respond($operation, 'integration', $subject, $terms, $operationKey);
    }
}

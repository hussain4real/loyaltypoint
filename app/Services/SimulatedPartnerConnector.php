<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\IntegrationOperation;
use App\Enums\IntegrationStatus;
use DomainException;
use InvalidArgumentException;

/** Explicit fixtures only: no conversion formula, remote calls or actual point movements. */
final class SimulatedPartnerConnector implements LoyaltyConnector
{
    private readonly IntegrationFixtureStore $store;

    /**
     * @param  array<string, IntegrationResult>  $fixtures  Keys are operation-name:connection-reference/subject.
     * @param  array<string, PartnerBalanceSnapshot>  $balances  Connection-reference keyed observations.
     */
    public function __construct(
        string $environment,
        private readonly IntegrationCapabilities $supported,
        array $fixtures,
        private readonly array $balances = [],
    ) {
        $this->store = new IntegrationFixtureStore($environment, $fixtures);
    }

    public function capabilities(): IntegrationCapabilities
    {
        return $this->supported;
    }

    public function authorize(PartnerConnectionContext $connection, string $state, string $operationKey): IntegrationResult
    {
        if (trim($state) === '') {
            throw new InvalidArgumentException('Delegated authorization requires anti-forgery state.');
        }

        return $this->respond(IntegrationOperation::Authorize, $connection, $connection->connectionReference, ['state' => $state], $operationKey);
    }

    public function revoke(PartnerConnectionContext $connection, string $operationKey): IntegrationResult
    {
        return $this->respond(IntegrationOperation::Revoke, $connection, $connection->connectionReference, [], $operationKey);
    }

    public function readBalance(PartnerConnectionContext $connection): PartnerBalanceSnapshot
    {
        $this->supported->require(IntegrationOperation::ReadBalance);
        $balance = $this->balances[$connection->connectionReference] ?? throw new DomainException('partner_unavailable');

        if ($balance->source !== $connection->source) {
            throw new DomainException('ownership_mismatch');
        }

        return $balance;
    }

    public function requestOffer(PartnerConnectionContext $connection, int $points, string $operationKey): IntegrationResult
    {
        if ($points < 1) {
            throw new InvalidArgumentException('Offer requests require positive points.');
        }

        $result = $this->respond(IntegrationOperation::RequestOffer, $connection, $connection->connectionReference.'/'.$points, ['points' => $points], $operationKey);

        if ($result->status === IntegrationStatus::Succeeded && ($result->offer === null || $result->offer->source !== $connection->source || $result->offer->points !== $points)) {
            throw new DomainException('partner_offer_invalid');
        }

        return $result;
    }

    public function confirmTerms(PartnerConnectionContext $connection, PartnerValueOffer $offer, string $operationKey): IntegrationResult
    {
        $this->assertSource($connection, $offer);
        $result = $this->respond(IntegrationOperation::ConfirmTerms, $connection, $connection->connectionReference.'/'.($offer->quoteReference ?? $offer->transactionReference ?? 'current'), $this->terms($offer), $operationKey);

        if ($result->status === IntegrationStatus::Succeeded && ($result->confirmation === null || ! $connection->hasSameOwnerAndConnectionAs($result->confirmation->connection))) {
            throw new DomainException('offer_unconfirmed');
        }

        return $result;
    }

    public function reserve(PartnerConnectionContext $connection, ConfirmedPartnerOffer $offer, string $operationKey): IntegrationResult
    {
        $this->assertSource($connection, $offer->offer);

        if (! $connection->hasSameOwnerAndConnectionAs($offer->connection)) {
            throw new DomainException('ownership_mismatch');
        }

        return $this->respond(IntegrationOperation::Reserve, $connection, $connection->connectionReference.'/'.$offer->confirmationReference, $this->terms($offer->offer), $operationKey);
    }

    public function confirmDebit(PartnerConnectionContext $connection, string $reservationReference, string $operationKey): IntegrationResult
    {
        return $this->respond(IntegrationOperation::ConfirmDebit, $connection, $reservationReference, [], $operationKey);
    }

    public function release(PartnerConnectionContext $connection, string $reservationReference, string $operationKey): IntegrationResult
    {
        return $this->respond(IntegrationOperation::Release, $connection, $reservationReference, [], $operationKey);
    }

    public function reverse(PartnerConnectionContext $connection, string $transactionReference, string $operationKey): IntegrationResult
    {
        return $this->respond(IntegrationOperation::Reverse, $connection, $transactionReference, [], $operationKey);
    }

    public function operationStatus(PartnerConnectionContext $connection, string $operationReference): IntegrationResult
    {
        $this->supported->require(IntegrationOperation::OperationStatus);

        return $this->store->status($this->scope($connection), $operationReference);
    }

    /** @param array<string, int|string|null> $terms */
    private function respond(IntegrationOperation $operation, PartnerConnectionContext $connection, string $subject, array $terms, string $key): IntegrationResult
    {
        $this->supported->require($operation);

        return $this->store->respond($operation, $this->scope($connection), $subject, $terms, $key);
    }

    private function scope(PartnerConnectionContext $connection): string
    {
        return hash('sha256', json_encode([$connection->userId, $connection->connectionReference, $connection->source], JSON_THROW_ON_ERROR));
    }

    private function assertSource(PartnerConnectionContext $connection, PartnerValueOffer $offer): void
    {
        if ($connection->source !== $offer->source) {
            throw new DomainException('ownership_mismatch');
        }
    }

    /** @return array<string, int|string|null> */
    private function terms(PartnerValueOffer $offer): array
    {
        return [
            'points' => $offer->points,
            'amount' => $offer->amount,
            'currency' => $offer->currency,
            'source' => $offer->source,
            'quote_reference' => $offer->quoteReference,
            'transaction_reference' => $offer->transactionReference,
            'observed_at' => $offer->observedAt->format('U.u'),
            'valid_from' => $offer->validFrom?->format('U.u'),
            'valid_until' => $offer->validUntil?->format('U.u'),
        ];
    }
}

<?php

declare(strict_types=1);

use App\Enums\IntegrationOperation;
use App\Enums\IntegrationStatus;
use App\Services\ConfirmedPartnerOffer;
use App\Services\IntegrationCapabilities;
use App\Services\IntegrationMoney;
use App\Services\IntegrationResult;
use App\Services\PartnerBalanceSnapshot;
use App\Services\PartnerConnectionContext;
use App\Services\PartnerValueOffer;
use App\Services\SimulatedPartnerConnector;
use App\Services\UnsupportedIntegrationOperation;

function connectorContext(int $userId = 1, string $connection = 'connection-1', string $source = 'partner-a/account-1'): PartnerConnectionContext
{
    return new PartnerConnectionContext($userId, $connection, $source, true, true, 'QAR');
}

/** @param array<string, mixed> $changes */
function connectorOffer(array $changes = []): PartnerValueOffer
{
    return PartnerValueOffer::fromSuppliedData(array_replace([
        'points' => 10_000,
        'amount' => '350.00',
        'currency' => 'QAR',
        'source' => 'partner-a/account-1',
        'observed_at' => new DateTimeImmutable('2026-10-26T10:00:00Z'),
        'quote_reference' => 'quote-1',
        'valid_until' => new DateTimeImmutable('2026-10-26T11:00:00Z'),
    ], $changes));
}

test('returns fixture-supplied value for requested points without deriving a conversion', function (): void {
    $offer = connectorOffer();
    $response = new IntegrationResult('offer-operation-1', IntegrationStatus::Succeeded, offer: $offer);
    $connector = new SimulatedPartnerConnector('testing', new IntegrationCapabilities([IntegrationOperation::RequestOffer]), ['request_offer:connection-1/10000' => $response]);

    $result = $connector->requestOffer(connectorContext(), 10_000, 'offer-key-1');

    expect($result)->toBe($response);
    expect($result->offer)->points->toBe(10_000)->amount->toBe('350.00')->currency->toBe('QAR');
    expect(fn () => $connector->requestOffer(connectorContext(), 20_000, 'offer-key-2'))->toThrow(DomainException::class, 'simulator_fixture_unavailable');
});

test('retries identical operations with one stable reference and rejects changed input for the same key', function (): void {
    $response = new IntegrationResult('offer-operation-1', IntegrationStatus::Succeeded, offer: connectorOffer());
    $connector = new SimulatedPartnerConnector('testing', new IntegrationCapabilities([IntegrationOperation::RequestOffer]), ['request_offer:connection-1/10000' => $response]);

    expect($connector->requestOffer(connectorContext(), 10_000, 'same-key'))->toBe($response);
    expect($connector->requestOffer(connectorContext(), 10_000, 'same-key'))->toBe($response);
    expect(fn () => $connector->requestOffer(connectorContext(), 10_001, 'same-key'))->toThrow(DomainException::class, 'idempotency_conflict');
});

test('retains pending and rejected offer outcomes without inventing supplied value', function (IntegrationStatus $status): void {
    $response = new IntegrationResult('offer-operation-1', $status);
    $connector = new SimulatedPartnerConnector('testing', new IntegrationCapabilities([IntegrationOperation::RequestOffer]), ['request_offer:connection-1/10000' => $response]);

    expect($connector->requestOffer(connectorContext(), 10_000, 'key'))->status->toBe($status)->offer->toBeNull()->confirmation->toBeNull();
})->with([IntegrationStatus::Pending, IntegrationStatus::Unknown, IntegrationStatus::Rejected]);

test('rejects a successful offer with missing changed quantity or cross-account data', function (?PartnerValueOffer $offer): void {
    $response = new IntegrationResult('offer-operation-1', IntegrationStatus::Succeeded, offer: $offer);
    $connector = new SimulatedPartnerConnector('testing', new IntegrationCapabilities([IntegrationOperation::RequestOffer]), ['request_offer:connection-1/10000' => $response]);

    expect(fn () => $connector->requestOffer(connectorContext(), 10_000, 'key'))->toThrow(DomainException::class, 'partner_offer_invalid');
})->with([
    'missing supplied amount' => [null],
    'changed quantity' => [connectorOffer(['points' => 9_999])],
    'different source' => [connectorOffer(['source' => 'partner-b/account-2'])],
]);

test('requires authoritative confirmation evidence for a successful confirmation response', function (): void {
    $connector = new SimulatedPartnerConnector('testing', new IntegrationCapabilities([IntegrationOperation::ConfirmTerms]), ['confirm_terms:connection-1/quote-1' => new IntegrationResult('confirmation-op', IntegrationStatus::Succeeded)]);

    expect(fn () => $connector->confirmTerms(connectorContext(), connectorOffer(), 'key'))->toThrow(DomainException::class, 'offer_unconfirmed');
});

test('keeps trusted confirmation evidence separate and detects conflicting retries', function (): void {
    $offer = connectorOffer();
    $confirmation = new ConfirmedPartnerOffer(connectorContext(), $offer, 'confirmation-1', new DateTimeImmutable('2026-10-26T10:01:00Z'));
    $response = new IntegrationResult('confirmation-op', IntegrationStatus::Succeeded, confirmation: $confirmation);
    $connector = new SimulatedPartnerConnector('testing', new IntegrationCapabilities([IntegrationOperation::ConfirmTerms]), ['confirm_terms:connection-1/quote-1' => $response]);

    expect($connector->confirmTerms(connectorContext(), $offer, 'key')->confirmation)->toBe($confirmation);
    expect(fn () => $connector->confirmTerms(connectorContext(), connectorOffer(['amount' => '351.00']), 'key'))->toThrow(DomainException::class, 'idempotency_conflict');
});

test('preserves an unknown reserve outcome for status recovery rather than treating timeout as failure', function (): void {
    $unknown = new IntegrationResult('reserve-operation-1', IntegrationStatus::Unknown);
    $confirmedStatus = new IntegrationResult('reserve-operation-1', IntegrationStatus::Succeeded, evidenceReference: 'partner-reservation-1');
    $connector = new SimulatedPartnerConnector('testing', new IntegrationCapabilities([IntegrationOperation::Reserve, IntegrationOperation::OperationStatus]), [
        'reserve:connection-1/confirmation-1' => $unknown,
        'operation_status:reserve-operation-1' => $confirmedStatus,
    ]);
    $confirmation = new ConfirmedPartnerOffer(connectorContext(), connectorOffer(), 'confirmation-1', new DateTimeImmutable('2026-10-26T10:01:00Z'));

    expect($connector->reserve(connectorContext(), $confirmation, 'reserve-key'))->status->toBe(IntegrationStatus::Unknown);
    expect($connector->reserve(connectorContext(), $confirmation, 'reserve-key'))->toBe($unknown);
    expect($connector->operationStatus(connectorContext(), 'reserve-operation-1'))->toBe($confirmedStatus);
    expect($connector->operationStatus(connectorContext(userId: 2), 'reserve-operation-1'))->status->toBe(IntegrationStatus::Unknown)->evidenceReference->toBeNull();
});

test('returns explicitly supplied debit release and reversal results', function (IntegrationOperation $operation): void {
    $result = new IntegrationResult('result-1', IntegrationStatus::Succeeded, evidenceReference: 'partner-transaction-1');
    $connector = new SimulatedPartnerConnector('testing', new IntegrationCapabilities([$operation]), [$operation->value.':partner-reservation-1' => $result]);

    $actual = match ($operation) {
        IntegrationOperation::ConfirmDebit => $connector->confirmDebit(connectorContext(), 'partner-reservation-1', 'key'),
        IntegrationOperation::Release => $connector->release(connectorContext(), 'partner-reservation-1', 'key'),
        IntegrationOperation::Reverse => $connector->reverse(connectorContext(), 'partner-reservation-1', 'key'),
    };

    expect($actual)->toBe($result);
})->with([IntegrationOperation::ConfirmDebit, IntegrationOperation::Release, IntegrationOperation::Reverse]);

test('declares unsupported reversal rather than fabricating compensation', function (): void {
    $connector = new SimulatedPartnerConnector('testing', new IntegrationCapabilities([]), []);

    expect($connector->capabilities()->supports(IntegrationOperation::Reverse))->toBeFalse();
    expect(fn () => $connector->reverse(connectorContext(), 'transaction-1', 'key'))->toThrow(UnsupportedIntegrationOperation::class, 'unsupported_integration_operation');
});

test('distinguishes a supported reversal endpoint from a guaranteed recovery capability', function (): void {
    $supported = new IntegrationCapabilities([IntegrationOperation::Reverse, IntegrationOperation::OperationStatus]);
    $guaranteed = new IntegrationCapabilities([IntegrationOperation::Reverse, IntegrationOperation::OperationStatus], guaranteedReversal: true);

    expect($supported->guaranteedReversal)->toBeFalse();
    expect($guaranteed->guaranteedReversal)->toBeTrue();
    expect(fn () => new IntegrationCapabilities([IntegrationOperation::Reverse], guaranteedReversal: true))->toThrow(InvalidArgumentException::class);
});

test('distinguishes a successful zero balance with unavailable value from refresh failure', function (): void {
    $zero = new PartnerBalanceSnapshot('partner-a/account-1', 0, new DateTimeImmutable('2026-10-26T10:00:00Z'));
    $connector = new SimulatedPartnerConnector('testing', new IntegrationCapabilities([IntegrationOperation::ReadBalance]), [], ['connection-1' => $zero]);

    expect($connector->readBalance(connectorContext()))->points->toBe(0)->suppliedValue->toBeNull();
    expect(fn () => $connector->readBalance(connectorContext(connection: 'missing-connection')))->toThrow(DomainException::class, 'partner_unavailable');
});

test('preserves balance supplied money and rejects cross-source observation', function (): void {
    $snapshot = new PartnerBalanceSnapshot('partner-a/account-1', 10_000, new DateTimeImmutable('2026-10-26T10:00:00Z'), new IntegrationMoney('350.00', 'QAR'), 'snapshot-1');
    $connector = new SimulatedPartnerConnector('testing', new IntegrationCapabilities([IntegrationOperation::ReadBalance]), [], ['connection-1' => $snapshot]);

    expect($connector->readBalance(connectorContext())->suppliedValue)->amount->toBe('350.00')->currency->toBe('QAR');
    expect(fn () => $connector->readBalance(connectorContext(source: 'partner-b/account-2')))->toThrow(DomainException::class, 'ownership_mismatch');
});

test('supports delegated authorization and revoke only with explicit fixtures and state', function (): void {
    $authorized = new IntegrationResult('auth-1', IntegrationStatus::Pending, artifactReference: 'partner-hosted-handoff-1');
    $revoked = new IntegrationResult('revoke-1', IntegrationStatus::Succeeded);
    $connector = new SimulatedPartnerConnector('testing', new IntegrationCapabilities([IntegrationOperation::Authorize, IntegrationOperation::Revoke]), [
        'authorize:connection-1' => $authorized,
        'revoke:connection-1' => $revoked,
    ]);

    expect($connector->authorize(connectorContext(), 'state-1', 'auth-key'))->toBe($authorized);
    expect($connector->revoke(connectorContext(), 'revoke-key'))->toBe($revoked);
    expect(fn () => $connector->authorize(connectorContext(), ' ', 'another-key'))->toThrow(InvalidArgumentException::class);
});

test('forbids simulator construction outside local and testing environments', function (string $environment): void {
    expect(fn () => new SimulatedPartnerConnector($environment, new IntegrationCapabilities([]), []))->toThrow(LogicException::class, 'simulator_environment_forbidden');
})->with(['production', 'staging', 'stage', '']);

test('rejects invalid operation keys and nonpositive offer requests', function (int $points, string $key): void {
    $connector = new SimulatedPartnerConnector('testing', new IntegrationCapabilities([IntegrationOperation::RequestOffer]), []);

    expect(fn () => $connector->requestOffer(connectorContext(), $points, $key))->toThrow(InvalidArgumentException::class);
})->with([[0, 'key'], [-1, 'key'], [10_000, ' ']]);

test('preserves distinct supplied balance references and both validity bounds without deriving value', function (): void {
    $observedAt = new DateTimeImmutable('2026-10-26T10:00:00.123456Z');
    $validFrom = new DateTimeImmutable('2026-10-26T09:00:00Z');
    $validUntil = new DateTimeImmutable('2026-10-26T11:00:00Z');
    $snapshot = new PartnerBalanceSnapshot(
        'partner-a/account-1', 10_000, $observedAt,
        new IntegrationMoney('000350.0000', 'QAR'), 'observation-1', $validUntil,
        validFrom: $validFrom, quoteReference: 'quote-1', transactionReference: 'transaction-1',
    );
    $connector = new SimulatedPartnerConnector('testing', new IntegrationCapabilities([IntegrationOperation::ReadBalance]), [], ['connection-1' => $snapshot]);

    $result = $connector->readBalance(connectorContext());

    expect($result)->toBe($snapshot)->reference->toBe('observation-1')
        ->quoteReference->toBe('quote-1')->transactionReference->toBe('transaction-1')
        ->observedAt->toBe($observedAt)->validFrom->toBe($validFrom)->validUntil->toBe($validUntil);
    expect($result->suppliedValue)->amount->toBe('000350.0000')->currency->toBe('QAR');
    expect(function () use ($result): void {
        $result->quoteReference = 'replacement';
    })->toThrow(Error::class);
});

test('keeps absent balance metadata unspecified and accepts one-sided validity as supplied data', function (): void {
    $at = new DateTimeImmutable('2026-10-26T10:00:00Z');
    $snapshot = new PartnerBalanceSnapshot('partner-a/account-1', 0, $at);
    $future = new PartnerBalanceSnapshot('partner-a/account-1', 0, $at, validFrom: new DateTimeImmutable('2030-01-01T00:00:00Z'));
    $stale = new PartnerBalanceSnapshot('partner-a/account-1', 0, $at, validUntil: new DateTimeImmutable('2020-01-01T00:00:00Z'));

    expect($snapshot)->reference->toBeNull()->quoteReference->toBeNull()->transactionReference->toBeNull()
        ->validFrom->toBeNull()->validUntil->toBeNull()->suppliedValue->toBeNull();
    expect($future->validFrom?->format('Y'))->toBe('2030');
    expect($stale->validUntil?->format('Y'))->toBe('2020');
});

test('rejects blank supplied balance reference fields', function (string $field): void {
    $fields = [$field => " \n"];

    expect(fn () => new PartnerBalanceSnapshot('partner-a/account-1', 10_000, new DateTimeImmutable('2026-10-26T10:00:00Z'), ...$fields))
        ->toThrow(InvalidArgumentException::class, 'Supplied balance references cannot be blank.');
})->with(['reference', 'quoteReference', 'transactionReference']);

test('rejects equal or reversed supplied balance validity bounds', function (string $end): void {
    expect(fn () => new PartnerBalanceSnapshot(
        'partner-a/account-1', 10_000, new DateTimeImmutable('2026-10-26T10:00:00Z'),
        validFrom: new DateTimeImmutable('2026-10-26T09:00:00Z'), validUntil: new DateTimeImmutable($end),
    ))->toThrow(InvalidArgumentException::class, 'Balance validity requires an exclusive end after its start.');
})->with(['2026-10-26T09:00:00Z', '2026-10-26T08:59:59.999999Z']);

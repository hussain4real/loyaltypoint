<?php

declare(strict_types=1);

use App\Enums\DeviceWallet;
use App\Enums\IntegrationOperation;
use App\Enums\IntegrationStatus;
use App\Enums\IssuerCardAction;
use App\Enums\SettlementDirection;
use App\Services\ConfirmedPartnerOffer;
use App\Services\IntegrationCapabilities;
use App\Services\IntegrationMoney;
use App\Services\IntegrationResult;
use App\Services\PartnerConnectionContext;
use App\Services\PartnerValueOffer;
use App\Services\SimulatedIntegrationGateway;
use App\Services\UnsupportedIntegrationOperation;

test('uses explicit external operation fixtures and preserves pending outcomes', function (string $fixture, callable $invoke): void {
    $response = new IntegrationResult('external-operation-1', IntegrationStatus::Pending, artifactReference: 'opaque-external-artifact-1');
    $gateway = new SimulatedIntegrationGateway('testing', new IntegrationCapabilities(IntegrationOperation::cases()), [$fixture => $response]);

    expect($invoke($gateway))->toBe($response)->status->toBe(IntegrationStatus::Pending);
})->with([
    'issuer enrollment' => ['enroll:user-1', fn (SimulatedIntegrationGateway $g) => $g->enroll('user-1', 'identity-evidence-1', 'key')],
    'virtual card issuance' => ['issue:enrollment-1', fn (SimulatedIntegrationGateway $g) => $g->issue('enrollment-1', 'key')],
    'tokenized reveal' => ['reveal:card-1', fn (SimulatedIntegrationGateway $g) => $g->reveal('card-1', 'step-up-1', 'key')],
    'freeze' => ['card_state:card-1', fn (SimulatedIntegrationGateway $g) => $g->changeCardState('card-1', IssuerCardAction::Freeze, 'key')],
    'Apple Pay provisioning' => ['provision:card-1', fn (SimulatedIntegrationGateway $g) => $g->provision('card-1', DeviceWallet::ApplePay, 'device-1', 'key')],
    'Google Pay provisioning' => ['provision:card-1', fn (SimulatedIntegrationGateway $g) => $g->provision('card-1', DeviceWallet::GooglePay, 'device-1', 'key')],
    'issuer funding request' => ['fund:wallet-1', fn (SimulatedIntegrationGateway $g) => $g->fund('wallet-1', new IntegrationMoney('308.00', 'QAR'), 'funding-evidence-1', 'key')],
    'issuer balance authority' => ['issuer_balance:wallet-1', fn (SimulatedIntegrationGateway $g) => $g->balance('wallet-1')],
    'card event' => ['card_event:event-1', fn (SimulatedIntegrationGateway $g) => $g->cardEvent('event-1')],
    'dispute routing' => ['dispute:card-1', fn (SimulatedIntegrationGateway $g) => $g->dispute('card-1', 'transaction-1', 'case-1', 'key')],
    'daily reconciliation feed' => ['daily_feed:1792972800.000000/1793059200.000000', fn (SimulatedIntegrationGateway $g) => $g->dailyFeed(new DateTimeImmutable('2026-10-26T00:00:00Z'), new DateTimeImmutable('2026-10-27T00:00:00Z'))],
    'identity and screening submission' => ['submit_screening:user-1', fn (SimulatedIntegrationGateway $g) => $g->submit('user-1', 'identity-evidence-1', 'key')],
    'screening review status' => ['screening_status:screening-case-1', fn (SimulatedIntegrationGateway $g) => $g->screeningStatus('screening-case-1')],
    're-verification' => ['reverify:screening-case-1', fn (SimulatedIntegrationGateway $g) => $g->reverify('screening-case-1', 'new-evidence-1', 'key')],
    'settlement file delivery' => ['deliver_settlement:file-1', fn (SimulatedIntegrationGateway $g) => $g->deliver('file-1', str_repeat('a', 64), 'QAR', SettlementDirection::Receivable, 'key')],
    'file acknowledgement' => ['settlement_acknowledgement:file-1', fn (SimulatedIntegrationGateway $g) => $g->acknowledgement('file-1')],
    'payment evidence' => ['payment_evidence:settlement-1', fn (SimulatedIntegrationGateway $g) => $g->paymentEvidence('settlement-1')],
]);

test('keeps delivery acknowledgement and actual payment evidence independent', function (): void {
    $delivered = new IntegrationResult('delivery-1', IntegrationStatus::Succeeded, evidenceReference: 'file-received-1');
    $unpaid = new IntegrationResult('payment-1', IntegrationStatus::Pending);
    $gateway = new SimulatedIntegrationGateway('testing', new IntegrationCapabilities([IntegrationOperation::SettlementAcknowledgement, IntegrationOperation::PaymentEvidence]), [
        'settlement_acknowledgement:file-1' => $delivered,
        'payment_evidence:settlement-1' => $unpaid,
    ]);

    expect($gateway->acknowledgement('file-1'))->status->toBe(IntegrationStatus::Succeeded)->evidenceReference->toBe('file-received-1');
    expect($gateway->paymentEvidence('settlement-1'))->status->toBe(IntegrationStatus::Pending)->evidenceReference->toBeNull();
});

test('rejects changed funding amount currency or backing evidence under the same operation key', function (IntegrationMoney $money, string $evidence): void {
    $result = new IntegrationResult('funding-1', IntegrationStatus::Unknown);
    $gateway = new SimulatedIntegrationGateway('testing', new IntegrationCapabilities([IntegrationOperation::Fund]), ['fund:wallet-1' => $result]);
    $gateway->fund('wallet-1', new IntegrationMoney('308.00', 'QAR'), 'backing-1', 'key');

    expect(fn () => $gateway->fund('wallet-1', $money, $evidence, 'key'))->toThrow(DomainException::class, 'idempotency_conflict');
})->with([
    'amount' => [new IntegrationMoney('309.00', 'QAR'), 'backing-1'],
    'currency' => [new IntegrationMoney('308.00', 'USD'), 'backing-1'],
    'funding evidence' => [new IntegrationMoney('308.00', 'QAR'), 'backing-2'],
]);

test('queries authoritative status for a stable external operation and preserves unknown references', function (): void {
    $gateway = new SimulatedIntegrationGateway('testing', new IntegrationCapabilities([IntegrationOperation::Issue, IntegrationOperation::OperationStatus]), [
        'issue:enrollment-1' => new IntegrationResult('issuance-1', IntegrationStatus::Unknown),
        'operation_status:issuance-1' => new IntegrationResult('issuance-1', IntegrationStatus::Succeeded, artifactReference: 'tokenized-card-1'),
    ]);
    $gateway->issue('enrollment-1', 'key');

    expect($gateway->operationStatus('issuance-1'))->status->toBe(IntegrationStatus::Succeeded)->artifactReference->toBe('tokenized-card-1');
    expect($gateway->operationStatus('missing-operation'))->status->toBe(IntegrationStatus::Unknown)->artifactReference->toBeNull();
});

test('does not promise unsupported device wallet funding or screening operations', function (callable $invoke): void {
    $gateway = new SimulatedIntegrationGateway('testing', new IntegrationCapabilities([]), []);

    expect(fn () => $invoke($gateway))->toThrow(UnsupportedIntegrationOperation::class);
})->with([
    'Apple Pay unsupported' => [fn (SimulatedIntegrationGateway $g) => $g->provision('card-1', DeviceWallet::ApplePay, 'device-1', 'key')],
    'funding unsupported' => [fn (SimulatedIntegrationGateway $g) => $g->fund('wallet-1', new IntegrationMoney('308.00', 'QAR'), 'backing-1', 'key')],
    'screening unsupported' => [fn (SimulatedIntegrationGateway $g) => $g->submit('user-1', 'evidence-1', 'key')],
]);

test('rejects malformed settlement checksum currency or blank references', function (string $checksum, string $currency, string $file): void {
    $gateway = new SimulatedIntegrationGateway('testing', new IntegrationCapabilities([IntegrationOperation::DeliverSettlement]), []);

    expect(fn () => $gateway->deliver($file, $checksum, $currency, SettlementDirection::Payable, 'key'))->toThrow(InvalidArgumentException::class);
})->with([
    'missing checksum' => ['', 'QAR', 'file-1'],
    'nonhex checksum' => [str_repeat('z', 64), 'QAR', 'file-1'],
    'invalid currency' => [str_repeat('a', 64), 'qar', 'file-1'],
    'blank file reference' => [str_repeat('a', 64), 'QAR', ' '],
]);

test('rejects empty sensitive evidence references and invalid reconciliation intervals', function (): void {
    $gateway = new SimulatedIntegrationGateway('testing', new IntegrationCapabilities([IntegrationOperation::Reveal, IntegrationOperation::DailyFeed]), []);

    expect(fn () => $gateway->reveal('card-1', ' ', 'key'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $gateway->dailyFeed(new DateTimeImmutable('2026-10-26T00:00:00Z'), new DateTimeImmutable('2026-10-26T00:00:00Z')))->toThrow(InvalidArgumentException::class);
});

test('refuses simulator production setup and malformed result fixtures', function (): void {
    expect(fn () => new SimulatedIntegrationGateway('production', new IntegrationCapabilities([]), []))->toThrow(LogicException::class, 'simulator_environment_forbidden');
    expect(fn () => new SimulatedIntegrationGateway('testing', new IntegrationCapabilities([]), ['issue:enrollment-1' => 'pretend-success']))->toThrow(InvalidArgumentException::class);
});

test('preserves exact supplied currency precision without floating point coercion', function (): void {
    $money = new IntegrationMoney('9007199254740993.0000000000000001', 'QAR');

    expect($money)->amount->toBe('9007199254740993.0000000000000001')->currency->toBe('QAR');
    expect(fn () => new IntegrationMoney('1e3', 'QAR'))->toThrow(InvalidArgumentException::class);
    expect(fn () => new IntegrationMoney('-1.00', 'QAR'))->toThrow(InvalidArgumentException::class);
});

test('cannot attach authoritative confirmation to a pending unknown or rejected result', function (IntegrationStatus $status): void {
    $connection = new PartnerConnectionContext(1, 'connection-1', 'partner-a/account-1', true, true, 'QAR');
    $offer = PartnerValueOffer::fromSuppliedData(['points' => 10_000, 'amount' => '350.00', 'currency' => 'QAR', 'source' => 'partner-a/account-1', 'observed_at' => new DateTimeImmutable('2026-10-26T10:00:00Z')]);
    $confirmation = new ConfirmedPartnerOffer($connection, $offer, 'confirmation-1', new DateTimeImmutable('2026-10-26T10:01:00Z'));

    expect(fn () => new IntegrationResult('operation-1', $status, confirmation: $confirmation))->toThrow(InvalidArgumentException::class);
})->with([IntegrationStatus::Pending, IntegrationStatus::Unknown, IntegrationStatus::Rejected]);

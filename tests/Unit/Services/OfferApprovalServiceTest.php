<?php

declare(strict_types=1);

use App\Services\ConfirmedPartnerOffer;
use App\Services\ConsumerOfferApproval;
use App\Services\OfferApprovalService;
use App\Services\PartnerConnectionContext;
use App\Services\PartnerValueOffer;

/** @param array<string, mixed> $changes */
function approvalOffer(array $changes = []): PartnerValueOffer
{
    return PartnerValueOffer::fromSuppliedData(array_replace([
        'points' => 10_000,
        'amount' => '350.00',
        'currency' => 'QAR',
        'source' => 'partner-a/account-1',
        'observed_at' => new DateTimeImmutable('2026-10-26T10:00:00Z'),
        'quote_reference' => 'quote-1',
        'transaction_reference' => 'transaction-1',
        'valid_from' => new DateTimeImmutable('2026-10-26T10:00:00Z'),
        'valid_until' => new DateTimeImmutable('2026-10-26T11:00:00Z'),
    ], $changes));
}

function approvalConnection(int $userId = 1, string $connection = 'connection-1', string $source = 'partner-a/account-1', bool $active = true, bool $eligible = true, ?string $currency = 'QAR'): PartnerConnectionContext
{
    return new PartnerConnectionContext($userId, $connection, $source, $active, $eligible, $currency);
}

function approvalConfirmation(?PartnerValueOffer $offer = null, ?PartnerConnectionContext $connection = null, ?DateTimeImmutable $confirmedAt = null, ?DateTimeImmutable $validUntil = null): ConfirmedPartnerOffer
{
    return new ConfirmedPartnerOffer($connection ?? approvalConnection(), $offer ?? approvalOffer(), 'confirmation-1', $confirmedAt ?? new DateTimeImmutable('2026-10-26T10:01:00Z'), $validUntil);
}

test('binds approval to authenticated owner connection and confirmed exact offered terms', function (): void {
    $service = new OfferApprovalService;
    $confirmation = approvalConfirmation();
    $now = new DateTimeImmutable('2026-10-26T10:02:00Z');

    $approval = $service->approve(approvalConnection(), $confirmation, $now);
    $service->assertReady(approvalConnection(), $approval, $confirmation, $now);

    expect($approval)->confirmedOffer->toBe($confirmation)->approvedAt->toBe($now);
    expect($approval->confirmedOffer->offer)->points->toBe(10_000)->amount->toBe('350.00')->currency->toBe('QAR');
});

test('does not treat changed offer references validity or economics as approved', function (array $changes): void {
    $service = new OfferApprovalService;
    $now = new DateTimeImmutable('2026-10-26T10:02:00Z');
    $approval = $service->approve(approvalConnection(), approvalConfirmation(), $now);
    $changed = approvalConfirmation(approvalOffer($changes));

    expect(fn () => $service->assertReady(approvalConnection(), $approval, $changed, $now))
        ->toThrow(DomainException::class, 'value_changed_reapproval_required');
})->with([
    'higher amount' => [['amount' => '351.00']],
    'lower amount' => [['amount' => '349.00']],
    'changed points' => [['points' => 9_999]],
    'changed quote identity' => [['quote_reference' => 'quote-2']],
    'removed quote identity' => [['quote_reference' => null]],
    'changed transaction identity' => [['transaction_reference' => 'transaction-2']],
    'changed start' => [['valid_from' => new DateTimeImmutable('2026-10-26T10:01:00Z')]],
    'changed end' => [['valid_until' => new DateTimeImmutable('2026-10-26T10:59:00Z')]],
    'removed validity' => [['valid_until' => null]],
]);

test('permits renewed explicit approval after changed value is presented', function (): void {
    $service = new OfferApprovalService;
    $now = new DateTimeImmutable('2026-10-26T10:02:00Z');
    $newConfirmation = approvalConfirmation(approvalOffer(['amount' => '349.00', 'quote_reference' => 'quote-2']));

    $newApproval = $service->approve(approvalConnection(), $newConfirmation, $now);
    $service->assertReady(approvalConnection(), $newApproval, $newConfirmation, $now);

    expect($newApproval->confirmedOffer->offer->amount)->toBe('349.00');
});

test('allows equivalent decimal spelling without rounding supplied money', function (): void {
    $service = new OfferApprovalService;
    $now = new DateTimeImmutable('2026-10-26T10:02:00Z');
    $approved = $service->approve(approvalConnection(), approvalConfirmation(), $now);
    $fresh = approvalConfirmation(approvalOffer(['amount' => '000350.0000']));

    $service->assertReady(approvalConnection(), $approved, $fresh, $now);

    expect($fresh->offer->amount)->toBe('000350.0000');
    expect($approved->confirmedOffer->offer->amount)->toBe('350.00');
});

test('requires current ownership active consent eligibility and currency policy', function (PartnerConnectionContext $context, string $error): void {
    $service = new OfferApprovalService;

    expect(fn () => $service->approve($context, approvalConfirmation(), new DateTimeImmutable('2026-10-26T10:02:00Z')))
        ->toThrow(DomainException::class, $error);
})->with([
    'different user' => [approvalConnection(userId: 2), 'ownership_mismatch'],
    'different connection' => [approvalConnection(connection: 'connection-2'), 'ownership_mismatch'],
    'different account' => [approvalConnection(source: 'partner-a/account-2'), 'ownership_mismatch'],
    'revoked' => [approvalConnection(active: false), 'connection_revoked'],
    'ineligible' => [approvalConnection(eligible: false), 'eligibility_required'],
    'unapproved currency policy' => [approvalConnection(currency: null), 'currency_policy_required'],
    'different currency' => [approvalConnection(currency: 'USD'), 'currency_mismatch'],
]);

test('rechecks current restrictions rather than the original eligible snapshot', function (): void {
    $service = new OfferApprovalService;
    $now = new DateTimeImmutable('2026-10-26T10:02:00Z');
    $approval = $service->approve(approvalConnection(), approvalConfirmation(), $now);

    expect(fn () => $service->assertReady(approvalConnection(eligible: false), $approval, approvalConfirmation(), $now))
        ->toThrow(DomainException::class, 'eligibility_required');
});

test('rejects expired not-yet-valid and future authoritative terms', function (ConfirmedPartnerOffer $confirmation, string $error): void {
    expect(fn () => (new OfferApprovalService)->approve(approvalConnection(), $confirmation, new DateTimeImmutable('2026-10-26T10:02:00Z')))
        ->toThrow(DomainException::class, $error);
})->with([
    'expired offer at boundary' => [approvalConfirmation(approvalOffer(['valid_until' => new DateTimeImmutable('2026-10-26T10:02:00Z')])), 'offer_expired'],
    'not-yet-valid offer' => [approvalConfirmation(approvalOffer(['valid_from' => new DateTimeImmutable('2026-10-26T10:03:00Z')])), 'offer_expired'],
    'expired confirmation at boundary' => [approvalConfirmation(validUntil: new DateTimeImmutable('2026-10-26T10:02:00Z')), 'offer_expired'],
    'future confirmation' => [approvalConfirmation(confirmedAt: new DateTimeImmutable('2026-10-26T10:03:00Z')), 'offer_unconfirmed'],
    'future observation' => [approvalConfirmation(approvalOffer(['observed_at' => new DateTimeImmutable('2026-10-26T10:03:00Z')]), confirmedAt: new DateTimeImmutable('2026-10-26T10:03:00Z')), 'offer_unconfirmed'],
]);

test('refuses to carry approval across expiry even if a new confirmation has no expiry', function (): void {
    $service = new OfferApprovalService;
    $approval = $service->approve(approvalConnection(), approvalConfirmation(validUntil: new DateTimeImmutable('2026-10-26T10:03:00Z')), new DateTimeImmutable('2026-10-26T10:02:00Z'));

    expect(fn () => $service->assertReady(approvalConnection(), $approval, approvalConfirmation(), new DateTimeImmutable('2026-10-26T10:03:00Z')))
        ->toThrow(DomainException::class, 'offer_expired');
});

test('rejects altered approval time before confirmation or in the future', function (string $approvedAt): void {
    $confirmation = approvalConfirmation();
    $approval = new ConsumerOfferApproval($confirmation, new DateTimeImmutable($approvedAt));

    expect(fn () => (new OfferApprovalService)->assertReady(approvalConnection(), $approval, $confirmation, new DateTimeImmutable('2026-10-26T10:02:00Z')))
        ->toThrow(DomainException::class, 'approval_invalid');
})->with(['2026-10-26T10:00:00Z', '2026-10-26T10:03:00Z']);

test('rejects confirmation with inconsistent source time or blank evidence', function (string $variant): void {
    expect(function () use ($variant): void {
        new ConfirmedPartnerOffer(
            $variant === 'source' ? approvalConnection(source: 'partner-b/account-2') : approvalConnection(),
            approvalOffer(),
            $variant === 'evidence' ? ' ' : 'confirmation-1',
            new DateTimeImmutable($variant === 'observation' ? '2026-10-26T09:59:00Z' : '2026-10-26T10:01:00Z'),
            $variant === 'expiry' ? new DateTimeImmutable('2026-10-26T10:01:00Z') : null,
        );
    })->toThrow(InvalidArgumentException::class);
})->with(['source', 'evidence', 'observation', 'expiry']);

test('prevents rewriting confirmed terms and consumer approval', function (): void {
    $approval = (new OfferApprovalService)->approve(approvalConnection(), approvalConfirmation(), new DateTimeImmutable('2026-10-26T10:02:00Z'));

    expect(function () use ($approval): void {
        $approval->confirmedOffer->offer->amount = '1.00';
    })->toThrow(Error::class);
    expect(function () use ($approval): void {
        $approval->approvedAt = new DateTimeImmutable('2026-10-26T10:00:00Z');
    })->toThrow(Error::class);
});

test('rejects a reconstructed approval that predates the offered validity start', function (): void {
    $confirmation = approvalConfirmation(approvalOffer(['valid_from' => new DateTimeImmutable('2026-10-26T10:02:00Z')]));
    $approval = new ConsumerOfferApproval($confirmation, new DateTimeImmutable('2026-10-26T10:01:00Z'));

    expect(fn () => (new OfferApprovalService)->assertReady(approvalConnection(), $approval, $confirmation, new DateTimeImmutable('2026-10-26T10:02:00Z')))
        ->toThrow(DomainException::class, 'offer_expired');
});

test('preserves a zero supplied value but blocks readiness until executable value is available', function (): void {
    $offer = approvalOffer(['amount' => '0.00']);
    $now = new DateTimeImmutable('2026-10-26T10:02:00Z');
    $service = new OfferApprovalService;

    expect($offer->amount)->toBe('0.00');
    expect(fn () => $service->approve(approvalConnection(), approvalConfirmation($offer), $now))->toThrow(DomainException::class, 'value_unavailable');
    $approval = $service->approve(approvalConnection(), approvalConfirmation(), $now);
    expect(fn () => $service->assertReady(approvalConnection(), $approval, approvalConfirmation($offer), $now))->toThrow(DomainException::class, 'value_unavailable');
});

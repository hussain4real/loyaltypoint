<?php

declare(strict_types=1);

use App\Services\PartnerValueOffer;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function suppliedPartnerOfferData(array $overrides = []): array
{
    return array_replace([
        'points' => 10_000,
        'amount' => '350.00',
        'currency' => 'QAR',
        'source' => 'partner-a/membership-123',
        'observed_at' => new DateTimeImmutable('2026-10-26T10:00:00+00:00'),
    ], $overrides);
}

test('preserves supplied points, exact monetary value and optional evidence', function (): void {
    $observedAt = new DateTimeImmutable('2026-10-26T10:00:00.123456+03:00');
    $validFrom = new DateTimeImmutable('2026-10-26T10:00:00+03:00');
    $validUntil = new DateTimeImmutable('2026-10-26T10:05:00+03:00');

    $offer = PartnerValueOffer::fromSuppliedData(suppliedPartnerOfferData([
        'amount' => '000350.0000',
        'observed_at' => $observedAt,
        'quote_reference' => 'quote-123',
        'transaction_reference' => 'transaction-456',
        'valid_from' => $validFrom,
        'valid_until' => $validUntil,
    ]));

    expect($offer)
        ->points->toBe(10_000)
        ->amount->toBe('000350.0000')
        ->currency->toBe('QAR')
        ->source->toBe('partner-a/membership-123')
        ->observedAt->toBe($observedAt)
        ->quoteReference->toBe('quote-123')
        ->transactionReference->toBe('transaction-456')
        ->validFrom->toBe($validFrom)
        ->validUntil->toBe($validUntil);
});

test('preserves supplied zero as data without choosing a minimum redemption value', function (string $amount): void {
    $offer = PartnerValueOffer::fromSuppliedData(suppliedPartnerOfferData(['amount' => $amount]));

    expect($offer->amount)->toBe($amount);
})->with(['0', '0.00', '000.000']);

test('preserves supplied precision without assuming two decimal currency units', function (string $currency, string $amount): void {
    $offer = PartnerValueOffer::fromSuppliedData(suppliedPartnerOfferData([
        'currency' => $currency,
        'amount' => $amount,
    ]));

    expect($offer)->currency->toBe($currency)->amount->toBe($amount);
})->with([
    'whole amount' => ['JPY', '350'],
    'three decimal places' => ['KWD', '350.123'],
    'additional supplied precision' => ['QAR', '350.123456789'],
]);

test('rejects missing mandatory supplied terms', function (string $field): void {
    $data = suppliedPartnerOfferData();
    unset($data[$field]);

    expect(fn () => PartnerValueOffer::fromSuppliedData($data))->toThrow(InvalidArgumentException::class);
})->with(['points', 'amount', 'currency', 'source', 'observed_at']);

test('rejects malformed supplied terms without scalar coercion', function (array $overrides): void {
    $data = suppliedPartnerOfferData($overrides);

    expect(fn () => PartnerValueOffer::fromSuppliedData($data))->toThrow(InvalidArgumentException::class);
})->with([
    'zero points' => [['points' => 0]],
    'negative points' => [['points' => -1]],
    'fractional points' => [['points' => 1.5]],
    'floating whole points' => [['points' => 10_000.0]],
    'string points' => [['points' => '10000']],
    'boolean points' => [['points' => true]],
    'null points' => [['points' => null]],
    'negative amount' => [['amount' => '-1.00']],
    'exponent amount' => [['amount' => '1e3']],
    'signed amount' => [['amount' => '+350.00']],
    'missing decimal fraction' => [['amount' => '350.']],
    'missing integer part' => [['amount' => '.35']],
    'amount whitespace' => [['amount' => ' 350.00 ']],
    'empty amount' => [['amount' => '']],
    'integer amount' => [['amount' => 350]],
    'float amount' => [['amount' => 350.00]],
    'null amount' => [['amount' => null]],
    'lowercase currency' => [['currency' => 'qar']],
    'short currency' => [['currency' => 'QA']],
    'nonletter currency' => [['currency' => 'Q4R']],
    'currency newline' => [['currency' => "QAR\n"]],
    'null currency' => [['currency' => null]],
    'blank source' => [['source' => '']],
    'whitespace source' => [['source' => "\t "]],
    'numeric source' => [['source' => 123]],
    'null source' => [['source' => null]],
    'unparsed observation' => [['observed_at' => '2026-10-26T10:00:00Z']],
    'mutable observation' => [['observed_at' => new DateTime('2026-10-26T10:00:00Z')]],
    'null observation' => [['observed_at' => null]],
    'blank quote reference' => [['quote_reference' => '']],
    'numeric quote reference' => [['quote_reference' => 123]],
    'blank transaction reference' => [['transaction_reference' => ' ']],
    'boolean transaction reference' => [['transaction_reference' => false]],
    'unparsed validity start' => [['valid_from' => '2026-10-26T10:00:00Z']],
    'mutable validity end' => [['valid_until' => new DateTime('2026-10-26T10:05:00Z')]],
]);

test('retains missing references and supplied validity as unspecified', function (): void {
    $offer = PartnerValueOffer::fromSuppliedData(suppliedPartnerOfferData());

    expect($offer)
        ->quoteReference->toBeNull()
        ->transactionReference->toBeNull()
        ->validFrom->toBeNull()
        ->validUntil->toBeNull();
    expect($offer->isWithinSuppliedValidityAt(new DateTimeImmutable('2026-10-27T10:00:00Z')))->toBeTrue();
});

test('accepts explicit null optional evidence', function (): void {
    $offer = PartnerValueOffer::fromSuppliedData(suppliedPartnerOfferData([
        'quote_reference' => null,
        'transaction_reference' => null,
        'valid_from' => null,
        'valid_until' => null,
    ]));

    expect($offer)
        ->quoteReference->toBeNull()
        ->transactionReference->toBeNull()
        ->validFrom->toBeNull()
        ->validUntil->toBeNull();
});

test('rejects empty or reversed supplied validity intervals', function (string $end): void {
    $data = suppliedPartnerOfferData([
        'valid_from' => new DateTimeImmutable('2026-10-26T10:00:00Z'),
        'valid_until' => new DateTimeImmutable($end),
    ]);

    expect(fn () => PartnerValueOffer::fromSuppliedData($data))->toThrow(InvalidArgumentException::class);
})->with([
    'equal bounds' => '2026-10-26T10:00:00Z',
    'reversed bounds' => '2026-10-26T09:59:59Z',
]);

test('checks supplied validity at an explicit instant with inclusive start and exclusive end', function (string $instant, bool $within): void {
    $offer = PartnerValueOffer::fromSuppliedData(suppliedPartnerOfferData([
        'valid_from' => new DateTimeImmutable('2026-10-26T10:00:00Z'),
        'valid_until' => new DateTimeImmutable('2026-10-26T10:05:00Z'),
    ]));

    expect($offer->isWithinSuppliedValidityAt(new DateTimeImmutable($instant)))->toBe($within);
})->with([
    'before start' => ['2026-10-26T09:59:59Z', false],
    'at start' => ['2026-10-26T10:00:00Z', true],
    'before end' => ['2026-10-26T10:04:59.999999Z', true],
    'at end' => ['2026-10-26T10:05:00Z', false],
    'after end' => ['2026-10-26T10:05:01Z', false],
    'same start in another timezone' => ['2026-10-26T13:00:00+03:00', true],
]);

test('checks a single supplied validity bound without inventing the other', function (array $bounds, string $instant, bool $within): void {
    $offer = PartnerValueOffer::fromSuppliedData(suppliedPartnerOfferData($bounds));

    expect($offer->isWithinSuppliedValidityAt(new DateTimeImmutable($instant)))->toBe($within);
})->with([
    'start only before start' => [['valid_from' => new DateTimeImmutable('2026-10-26T10:00:00Z')], '2026-10-26T09:59:59Z', false],
    'start only after start' => [['valid_from' => new DateTimeImmutable('2026-10-26T10:00:00Z')], '2026-10-27T10:00:00Z', true],
    'end only before end' => [['valid_until' => new DateTimeImmutable('2026-10-26T10:05:00Z')], '2026-10-26T10:00:00Z', true],
    'end only at end' => [['valid_until' => new DateTimeImmutable('2026-10-26T10:05:00Z')], '2026-10-26T10:05:00Z', false],
]);

test('detects changed scoped economic terms in either monetary direction', function (array $changes): void {
    $accepted = PartnerValueOffer::fromSuppliedData(suppliedPartnerOfferData());
    $changed = PartnerValueOffer::fromSuppliedData(suppliedPartnerOfferData($changes));

    expect($changed->hasSameEconomicTermsAs($accepted))->toBeFalse();
    expect($accepted->hasSameEconomicTermsAs($changed))->toBeFalse();
})->with([
    'increased value' => [['amount' => '351.00']],
    'decreased value' => [['amount' => '349.00']],
    'zero supplied value' => [['amount' => '0.00']],
    'different points' => [['points' => 10_001]],
    'different currency' => [['currency' => 'USD']],
    'different partner/account' => [['source' => 'partner-a/membership-456']],
]);

test('compares decimal values exactly while preserving equivalent supplied spellings', function (string $amount): void {
    $accepted = PartnerValueOffer::fromSuppliedData(suppliedPartnerOfferData());
    $refreshed = PartnerValueOffer::fromSuppliedData(suppliedPartnerOfferData(['amount' => $amount]));

    expect($refreshed->hasSameEconomicTermsAs($accepted))->toBeTrue();
    expect($refreshed->amount)->toBe($amount);
})->with(['350', '350.0', '350.0000', '000350.00']);

test('distinguishes changes beyond floating point precision', function (): void {
    $accepted = PartnerValueOffer::fromSuppliedData(suppliedPartnerOfferData(['amount' => '9007199254740993.0000000000000001']));
    $changed = PartnerValueOffer::fromSuppliedData(suppliedPartnerOfferData(['amount' => '9007199254740993.0000000000000002']));

    expect($changed->hasSameEconomicTermsAs($accepted))->toBeFalse();
    expect($changed->amount)->toBe('9007199254740993.0000000000000002');
});

test('keeps economic equality separate from expired or changed evidence', function (): void {
    $accepted = PartnerValueOffer::fromSuppliedData(suppliedPartnerOfferData(['quote_reference' => 'quote-123']));
    $expired = PartnerValueOffer::fromSuppliedData(suppliedPartnerOfferData([
        'amount' => '350.000',
        'quote_reference' => 'quote-456',
        'observed_at' => new DateTimeImmutable('2026-10-26T10:01:00Z'),
        'valid_until' => new DateTimeImmutable('2026-10-26T10:02:00Z'),
    ]));

    expect($expired->hasSameEconomicTermsAs($accepted))->toBeTrue();
    expect($expired->isWithinSuppliedValidityAt(new DateTimeImmutable('2026-10-26T10:02:00Z')))->toBeFalse();
    expect($expired->quoteReference)->toBe('quote-456');
});

test('prevents rewriting supplied terms after capture', function (): void {
    $offer = PartnerValueOffer::fromSuppliedData(suppliedPartnerOfferData());

    expect(function () use ($offer): void {
        $offer->amount = '1.00';
    })->toThrow(Error::class);
});

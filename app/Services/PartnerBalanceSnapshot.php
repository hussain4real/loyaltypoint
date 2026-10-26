<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use InvalidArgumentException;

/** Zero points is a successful observation; unavailable refresh is an integration failure. */
final readonly class PartnerBalanceSnapshot
{
    public function __construct(
        public string $source,
        public int $points,
        public DateTimeImmutable $observedAt,
        public ?IntegrationMoney $suppliedValue = null,
        public ?string $reference = null,
        public ?DateTimeImmutable $validUntil = null,
        public ?DateTimeImmutable $validFrom = null,
        public ?string $quoteReference = null,
        public ?string $transactionReference = null,
    ) {
        if (trim($source) === '' || $points < 0) {
            throw new InvalidArgumentException('A balance observation requires a scoped source and non-negative points.');
        }

        foreach ([$reference, $quoteReference, $transactionReference] as $suppliedReference) {
            if ($suppliedReference !== null && trim($suppliedReference) === '') {
                throw new InvalidArgumentException('Supplied balance references cannot be blank.');
            }
        }

        if ($validFrom !== null && $validUntil !== null && $validUntil <= $validFrom) {
            throw new InvalidArgumentException('Balance validity requires an exclusive end after its start.');
        }
    }
}

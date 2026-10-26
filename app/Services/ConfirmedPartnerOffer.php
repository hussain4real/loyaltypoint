<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Evidence returned by a trusted authenticated connector. Never construct from consumer JSON.
 * An instance represents an adapter assertion; real source authentication remains the adapter's duty.
 */
final readonly class ConfirmedPartnerOffer
{
    public function __construct(
        public PartnerConnectionContext $connection,
        public PartnerValueOffer $offer,
        public string $confirmationReference,
        public DateTimeImmutable $confirmedAt,
        public ?DateTimeImmutable $confirmationValidUntil = null,
    ) {
        if ($connection->source !== $offer->source || trim($confirmationReference) === '') {
            throw new InvalidArgumentException('Confirmation must identify its scoped source and evidence reference.');
        }

        if ($confirmedAt < $offer->observedAt || ($confirmationValidUntil !== null && $confirmationValidUntil <= $confirmedAt)) {
            throw new InvalidArgumentException('Confirmation evidence has inconsistent timing.');
        }
    }
}

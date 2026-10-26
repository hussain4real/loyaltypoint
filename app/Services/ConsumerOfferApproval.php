<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;

/** Approval snapshot created only after an authenticated consumer accepts displayed terms. */
final readonly class ConsumerOfferApproval
{
    public function __construct(
        public ConfirmedPartnerOffer $confirmedOffer,
        public DateTimeImmutable $approvedAt,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\IntegrationStatus;
use InvalidArgumentException;

/** A provider operation result; success of delivery is never payment/funding evidence. */
final readonly class IntegrationResult
{
    public function __construct(
        public string $operationReference,
        public IntegrationStatus $status,
        public ?PartnerValueOffer $offer = null,
        public ?ConfirmedPartnerOffer $confirmation = null,
        public ?IntegrationMoney $money = null,
        public ?string $evidenceReference = null,
        public ?string $artifactReference = null,
    ) {
        if (trim($operationReference) === '') {
            throw new InvalidArgumentException('A result requires a stable operation reference.');
        }

        if ($confirmation !== null && $status !== IntegrationStatus::Succeeded) {
            throw new InvalidArgumentException('Unsuccessful operations cannot carry authoritative confirmation.');
        }

        foreach ([$evidenceReference, $artifactReference] as $reference) {
            if ($reference !== null && trim($reference) === '') {
                throw new InvalidArgumentException('Supplied evidence references cannot be blank.');
            }
        }
    }
}

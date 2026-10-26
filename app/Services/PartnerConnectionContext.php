<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

/** Trusted current ownership/eligibility, loaded by the application rather than request input. */
final readonly class PartnerConnectionContext
{
    public function __construct(
        public int $userId,
        public string $connectionReference,
        public string $source,
        public bool $active,
        public bool $eligible,
        public ?string $walletCurrency,
    ) {
        if ($userId < 1 || trim($connectionReference) === '' || trim($source) === '') {
            throw new InvalidArgumentException('A connection requires authenticated ownership and scoped references.');
        }

        if ($walletCurrency !== null && preg_match('/\A[A-Z]{3}\z/', $walletCurrency) !== 1) {
            throw new InvalidArgumentException('The wallet currency must be an uppercase three-letter code.');
        }
    }

    public function hasSameOwnerAndConnectionAs(self $other): bool
    {
        return $this->userId === $other->userId
            && $this->connectionReference === $other->connectionReference
            && $this->source === $other->source;
    }
}

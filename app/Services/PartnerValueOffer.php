<?php

declare(strict_types=1);

namespace App\Services;

use Brick\Math\BigDecimal;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Supplied terms and evidence, not proof of partner confirmation or consumer approval.
 */
final readonly class PartnerValueOffer
{
    private function __construct(
        public int $points,
        public string $amount,
        public string $currency,
        public string $source,
        public DateTimeImmutable $observedAt,
        public ?string $quoteReference,
        public ?string $transactionReference,
        public ?DateTimeImmutable $validFrom,
        public ?DateTimeImmutable $validUntil,
    ) {}

    /**
     * Accept canonical adapter data without choosing a partner wire format or currency policy.
     *
     * @param  array{
     *     points?: mixed,
     *     amount?: mixed,
     *     currency?: mixed,
     *     source?: mixed,
     *     observed_at?: mixed,
     *     quote_reference?: mixed,
     *     transaction_reference?: mixed,
     *     valid_from?: mixed,
     *     valid_until?: mixed
     * }  $data
     *
     * @throws InvalidArgumentException
     */
    public static function fromSuppliedData(array $data): self
    {
        $points = $data['points'] ?? null;

        if (! is_int($points) || $points < 1) {
            throw new InvalidArgumentException('The supplied points must be a positive integer.');
        }

        $amount = $data['amount'] ?? null;

        if (! is_string($amount) || preg_match('/\A[0-9]+(?:\.[0-9]+)?\z/', $amount) !== 1) {
            throw new InvalidArgumentException('The supplied amount must be a non-negative plain decimal string.');
        }

        $currency = $data['currency'] ?? null;

        if (! is_string($currency) || preg_match('/\A[A-Z]{3}\z/', $currency) !== 1) {
            throw new InvalidArgumentException('The supplied currency must be an uppercase three-letter code.');
        }

        $source = $data['source'] ?? null;

        if (! is_string($source) || trim($source) === '') {
            throw new InvalidArgumentException('The supplied source must be a nonblank partner/account reference.');
        }

        $observedAt = $data['observed_at'] ?? null;

        if (! $observedAt instanceof DateTimeImmutable) {
            throw new InvalidArgumentException('The observation time must be an immutable date.');
        }

        $quoteReference = self::optionalReference($data, 'quote_reference');
        $transactionReference = self::optionalReference($data, 'transaction_reference');
        $validFrom = self::optionalDate($data, 'valid_from');
        $validUntil = self::optionalDate($data, 'valid_until');

        if ($validFrom !== null && $validUntil !== null && $validFrom >= $validUntil) {
            throw new InvalidArgumentException('The supplied validity end must be after its start.');
        }

        return new self(
            $points,
            $amount,
            $currency,
            $source,
            $observedAt,
            $quoteReference,
            $transactionReference,
            $validFrom,
            $validUntil,
        );
    }

    /**
     * Check supplied bounds only, with an inclusive start and exclusive end.
     * Missing bounds establish neither freshness nor permission to redeem.
     */
    public function isWithinSuppliedValidityAt(DateTimeImmutable $at): bool
    {
        return ($this->validFrom === null || $at >= $this->validFrom)
            && ($this->validUntil === null || $at < $this->validUntil);
    }

    /**
     * Compare scoped points, monetary value and currency independently of evidence.
     * Equal economics do not authorize reuse of approval for a new or expired quote.
     */
    public function hasSameEconomicTermsAs(self $other): bool
    {
        return $this->source === $other->source
            && $this->points === $other->points
            && $this->currency === $other->currency
            && BigDecimal::of($this->amount)->isEqualTo($other->amount);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function optionalReference(array $data, string $field): ?string
    {
        $reference = $data[$field] ?? null;

        if ($reference !== null && (! is_string($reference) || trim($reference) === '')) {
            throw new InvalidArgumentException("The supplied {$field} must be a nonblank string or null.");
        }

        return $reference;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function optionalDate(array $data, string $field): ?DateTimeImmutable
    {
        $date = $data[$field] ?? null;

        if ($date !== null && ! $date instanceof DateTimeImmutable) {
            throw new InvalidArgumentException("The supplied {$field} must be an immutable date or null.");
        }

        return $date;
    }
}

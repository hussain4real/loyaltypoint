<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;

/** Normalized authenticated adapter evidence, never a consumer-supplied KYC decision. */
final readonly class ConsumerVerificationDecision
{
    public const CHECKS = ['document', 'liveness', 'identity', 'sanctions', 'pep', 'adverse_media'];

    /** @param array<string, mixed> $payload */
    public function __construct(public string $source, public array $payload)
    {
        $keys = ['event_reference', 'revision_reference', 'operation_reference', 'checks', 'occurred_at', 'evidence_reference'];
        if (array_diff(array_keys($payload), $keys) !== [] || array_diff($keys, array_keys($payload)) !== [] || preg_match('/\A[a-z0-9-]{1,64}\z/', $source) !== 1) {
            throw new ConsumerIdentityFailure('invalid_verification_callback', 422);
        }
        foreach (['event_reference', 'revision_reference', 'operation_reference', 'evidence_reference'] as $key) {
            if (! is_string($payload[$key]) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:\/-]{0,179}\z/', $payload[$key]) !== 1) {
                throw new ConsumerIdentityFailure('invalid_verification_callback', 422);
            }
        }
        $checks = $payload['checks'];
        if (! is_array($checks) || array_diff(array_keys($checks), self::CHECKS) !== [] || array_diff(self::CHECKS, array_keys($checks)) !== []) {
            throw new ConsumerIdentityFailure('invalid_verification_callback', 422);
        }
        foreach ($checks as $check) {
            if (! in_array($check, ['clear', 'hit', 'rejected', 'pending'], true)) {
                throw new ConsumerIdentityFailure('invalid_verification_callback', 422);
            }
        }
        if (! is_string($payload['occurred_at']) || preg_match('/\A(?!0000)\d{4}-\d{2}-\d{2}T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)\z/', $payload['occurred_at']) !== 1) {
            throw new ConsumerIdentityFailure('invalid_verification_callback', 422);
        }
        try {
            $date = new DateTimeImmutable($payload['occurred_at']);
        } catch (\Exception) {
            throw new ConsumerIdentityFailure('invalid_verification_callback', 422);
        }
        if (DateTimeImmutable::getLastErrors() !== false || $date->getTimestamp() > now()->timestamp + (int) config('pull2spend.callback_skew_seconds', 300)) {
            throw new ConsumerIdentityFailure('invalid_verification_callback', 422);
        }
    }

    public function observedAt(): DateTimeImmutable
    {
        return (new DateTimeImmutable($this->payload['occurred_at']))->setTimezone(new DateTimeZone('UTC'));
    }
}

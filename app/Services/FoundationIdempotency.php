<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

final class FoundationIdempotency
{
    /** @param array<string, mixed> $request */
    public static function fingerprint(array $request): string
    {
        $canonical = function (mixed $value) use (&$canonical): mixed {
            if (! is_array($value)) {
                if (is_float($value)) {
                    throw new InvalidArgumentException('Decimal amounts must be strings.');
                }

                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            return array_map($canonical, $value);
        };

        return hash('sha256', json_encode($canonical($request), JSON_THROW_ON_ERROR));
    }

    public static function key(int $actorId, string $operation, string $key): string
    {
        if (preg_match('/\A[A-Za-z0-9_.:-]{8,128}\z/', $key) !== 1) {
            throw new FoundationFailure('invalid_idempotency_key', 422, 'provide_stable_key');
        }

        return hash('sha256', $actorId.'|'.$operation.'|'.$key);
    }
}

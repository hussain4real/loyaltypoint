<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

/** Supplied exact money, without rounding or currency conversion. */
final readonly class IntegrationMoney
{
    public function __construct(public string $amount, public string $currency)
    {
        if (preg_match('/\A[0-9]+(?:\.[0-9]+)?\z/', $amount) !== 1 || preg_match('/\A[A-Z]{3}\z/', $currency) !== 1) {
            throw new InvalidArgumentException('Money requires an exact non-negative decimal and currency code.');
        }
    }
}

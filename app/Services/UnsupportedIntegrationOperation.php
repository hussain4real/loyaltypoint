<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\IntegrationOperation;
use LogicException;

final class UnsupportedIntegrationOperation extends LogicException
{
    public function __construct(public readonly IntegrationOperation $operation)
    {
        parent::__construct('unsupported_integration_operation');
    }
}

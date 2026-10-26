<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\IntegrationOperation;
use InvalidArgumentException;

final readonly class IntegrationCapabilities
{
    /** @param list<IntegrationOperation> $operations Absent operations are unsupported. */
    public function __construct(public array $operations, public bool $guaranteedReversal = false)
    {
        foreach ($operations as $operation) {
            if (! $operation instanceof IntegrationOperation) {
                throw new InvalidArgumentException('Capabilities require explicit operation names.');
            }
        }

        if ($guaranteedReversal && (! $this->supports(IntegrationOperation::Reverse) || ! $this->supports(IntegrationOperation::OperationStatus))) {
            throw new InvalidArgumentException('Guaranteed reversal requires reversal and authoritative status operations.');
        }
    }

    public function supports(IntegrationOperation $operation): bool
    {
        return in_array($operation, $this->operations, true);
    }

    public function require(IntegrationOperation $operation): void
    {
        if (! $this->supports($operation)) {
            throw new UnsupportedIntegrationOperation($operation);
        }
    }
}

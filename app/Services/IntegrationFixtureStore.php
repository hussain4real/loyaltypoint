<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\IntegrationOperation;
use App\Enums\IntegrationStatus;
use DomainException;
use InvalidArgumentException;
use LogicException;

/** In-memory contract simulator storage, never production recovery or financial authority. */
final class IntegrationFixtureStore
{
    /** @var array<string, array{fingerprint: string, result: IntegrationResult}> */
    private array $requests = [];

    /** @var array<string, IntegrationResult> */
    private array $results = [];

    /** @param array<string, IntegrationResult> $fixtures Keys are operation-name:subject-reference. */
    public function __construct(string $environment, private readonly array $fixtures)
    {
        if (! in_array($environment, ['local', 'testing'], true)) {
            throw new LogicException('simulator_environment_forbidden');
        }

        foreach ($fixtures as $fixture) {
            if (! $fixture instanceof IntegrationResult) {
                throw new InvalidArgumentException('Simulator fixtures must be typed results.');
            }
        }
    }

    /** @param array<string, int|string|null> $terms */
    public function respond(
        IntegrationOperation $operation,
        string $scope,
        string $subject,
        array $terms,
        ?string $operationKey = null,
    ): IntegrationResult {
        if (trim($scope) === '' || trim($subject) === '' || ($operationKey !== null && trim($operationKey) === '')) {
            throw new InvalidArgumentException('An operation requires scope, subject and a nonblank key where supplied.');
        }

        $fingerprint = hash('sha256', json_encode([$subject, $terms], JSON_THROW_ON_ERROR));
        $key = $operationKey === null ? null : hash('sha256', json_encode([$scope, $operation->value, $operationKey], JSON_THROW_ON_ERROR));

        if ($key !== null && isset($this->requests[$key])) {
            if ($this->requests[$key]['fingerprint'] !== $fingerprint) {
                throw new DomainException('idempotency_conflict');
            }

            return $this->requests[$key]['result'];
        }

        $fixture = $this->fixtures[$operation->value.':'.$subject] ?? throw new DomainException('simulator_fixture_unavailable');
        $resultKey = $scope.':'.$fixture->operationReference;

        if (isset($this->results[$resultKey]) && $this->results[$resultKey] !== $fixture) {
            throw new LogicException('simulator_operation_reference_conflict');
        }

        $this->results[$resultKey] = $fixture;

        if ($key !== null) {
            $this->requests[$key] = ['fingerprint' => $fingerprint, 'result' => $fixture];
        }

        return $fixture;
    }

    public function status(string $scope, string $operationReference): IntegrationResult
    {
        if (trim($scope) === '' || trim($operationReference) === '') {
            throw new InvalidArgumentException('Status lookup requires scope and an operation reference.');
        }

        $fixture = $this->fixtures[IntegrationOperation::OperationStatus->value.':'.$operationReference] ?? null;

        if ($fixture !== null && isset($this->results[$scope.':'.$operationReference])) {
            if ($fixture->operationReference !== $operationReference) {
                throw new LogicException('simulator_status_reference_mismatch');
            }

            return $fixture;
        }

        return $this->results[$scope.':'.$operationReference]
            ?? new IntegrationResult($operationReference, IntegrationStatus::Unknown);
    }
}

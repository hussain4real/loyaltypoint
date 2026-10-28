<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use RuntimeException;

final class ConsumerIdentityFailure extends RuntimeException implements ShouldntReport
{
    public function __construct(public readonly string $errorCode, public readonly int $httpStatus = 403, public readonly string $nextAction = 'contact_support')
    {
        parent::__construct($errorCode);
    }

    public function render(): JsonResponse
    {
        return response()->json(['error' => ['code' => $this->errorCode, 'next_action' => $this->nextAction]], $this->httpStatus);
    }
}

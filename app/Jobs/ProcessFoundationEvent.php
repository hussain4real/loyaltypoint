<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\FoundationEvents;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessFoundationEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public readonly string $eventId) {}

    public function handle(FoundationEvents $events): void
    {
        $events->process($this->eventId);
    }
}

<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\ConsumerContactService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeliverConsumerContactCode implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public readonly string $deliveryId)
    {
        $this->onQueue(config('queue.connections.database.queue', 'default').'.notifications');
    }

    public function handle(ConsumerContactService $contacts): void
    {
        $contacts->deliver($this->deliveryId);
    }
}

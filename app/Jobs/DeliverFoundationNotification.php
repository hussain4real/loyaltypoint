<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\FoundationNotifications;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeliverFoundationNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public readonly string $deliveryId) {}

    public function handle(FoundationNotifications $notifications): void
    {
        $notifications->deliver($this->deliveryId);
    }
}

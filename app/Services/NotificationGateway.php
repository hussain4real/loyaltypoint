<?php

declare(strict_types=1);

namespace App\Services;

interface NotificationGateway
{
    public function supportsIdempotency(): bool;

    public function status(string $deliveryId): ?string;

    public function send(string $deliveryId, int $userId, string $template, string $reference): string;
}

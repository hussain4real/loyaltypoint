<?php

declare(strict_types=1);

namespace App\Services;

final class UnavailableNotificationGateway implements NotificationGateway
{
    public function supportsIdempotency(): bool
    {
        return false;
    }

    public function status(string $deliveryId): ?string
    {
        throw new FoundationFailure('delivery_unavailable', 503, 'configure_transport');
    }

    public function send(string $deliveryId, int $userId, string $template, string $reference): string
    {
        throw new FoundationFailure('delivery_unavailable', 503, 'configure_transport');
    }
}

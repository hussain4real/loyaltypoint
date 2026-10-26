<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Persists simulator receipts only; never sends mail, SMS or push. */
final class SimulatedNotificationGateway implements NotificationGateway
{
    public function supportsIdempotency(): bool
    {
        return true;
    }

    public function status(string $deliveryId): ?string
    {
        $this->ensureSandbox();

        return DB::table('foundation_delivery_receipts')->where('delivery_id', $deliveryId)->value('reference');
    }

    public function send(string $deliveryId, int $userId, string $template, string $reference): string
    {
        $this->ensureSandbox();
        DB::table('foundation_delivery_receipts')->insertOrIgnore(['delivery_id' => $deliveryId, 'reference' => 'simulated:'.$deliveryId, 'created_at' => now()]);

        return $this->status($deliveryId);
    }

    private function ensureSandbox(): void
    {
        if (! app()->environment('local', 'testing') || ! config('pull2spend.simulators_enabled')) {
            throw new FoundationFailure('delivery_unavailable', 503, 'configure_transport');
        }
    }
}

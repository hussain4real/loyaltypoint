<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\FoundationDelivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class FoundationNotifications
{
    public function __construct(private FoundationClaims $claims, private NotificationGateway $gateway, private CaseService $cases) {}

    public function enqueue(int $userId, string $template, string $reference): FoundationDelivery
    {
        if (! in_array($template, ['operation_status', 'security_event', 'case_update'], true) || preg_match('/\A[A-Za-z0-9_.:-]{1,128}\z/', $reference) !== 1) {
            throw new FoundationFailure('invalid_notification', 422, 'correct_template');
        }

        return FoundationDelivery::query()->createOrFirst(['deduplication_key' => hash('sha256', $userId.'|'.$template.'|'.$reference)], [
            'id' => (string) Str::uuid(), 'user_id' => $userId, 'payload' => ['template' => $template, 'reference' => $reference],
            'state' => 'pending', 'attempts' => 0, 'available_at' => now(),
        ]);
    }

    public function deliver(string $id): void
    {
        $record = FoundationDelivery::query()->find($id);
        if ($record === null || in_array($record->state, ['sent', 'failed'], true)) {
            return;
        }
        $token = $this->claims->acquire(new FoundationDelivery, $id);
        if ($token === null) {
            return;
        }
        $record->refresh();
        try {
            $receipt = $this->gateway->status($id);
            if ($receipt === null) {
                if ($record->attempts > 1 && ! $this->gateway->supportsIdempotency()) {
                    throw new FoundationFailure('delivery_unknown', 202, 'query_before_resend');
                }
                $receipt = $this->gateway->send($id, $record->user_id, $record->payload['template'], $record->payload['reference']);
            }
            FoundationDelivery::query()->whereKey($id)->where('state', 'processing')->where('lease_token', $token)
                ->update(['state' => 'sent', 'provider_reference' => $receipt, 'completed_at' => now(), 'lease_token' => null, 'lease_expires_at' => null, 'last_error_code' => null]);
        } catch (Throwable $exception) {
            $knownSafeRetry = $exception instanceof FoundationFailure && $exception->errorCode === 'delivery_unavailable';
            $state = $knownSafeRetry ? ($record->attempts >= config('pull2spend.event_attempts') ? 'failed' : 'pending') : 'unknown';
            DB::transaction(function () use ($record, $token, $state): void {
                if ($this->claims->release($record, $token, $state === 'unknown' ? 'delivery_unknown' : 'delivery_unavailable', $state) && $state !== 'pending') {
                    $this->cases->open('notification', $record->id, customerId: $record->user_id);
                }
            });
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\FoundationEvent;
use App\Models\FoundationOperation;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

final class FoundationEvents
{
    public function __construct(private FoundationClaims $claims, private AuditService $audit, private CaseService $cases) {}

    public function accept(string $source, string $body, string $timestamp, string $signature): FoundationEvent
    {
        if (! config('pull2spend.callbacks_enabled')) {
            throw new FoundationFailure('capability_disabled', 503, 'retry_later');
        }
        $settings = config('pull2spend.callback_sources.'.$source);
        if (! is_array($settings) || ($settings['mode'] ?? null) !== 'simulator' || ! app()->environment('local', 'testing')) {
            throw new FoundationFailure('callback_source_unavailable', 503, 'contact_operations');
        }
        $secret = $settings['secret'] ?? null;
        if (! is_string($secret) || strlen($secret) < 32 || strlen($body) > config('pull2spend.callback_max_bytes')) {
            throw new FoundationFailure('callback_rejected', 401, 'verify_callback');
        }
        if (preg_match('/\A[0-9]{1,11}\z/', $timestamp) !== 1 || abs(now()->timestamp - (int) $timestamp) > config('pull2spend.callback_skew_seconds')
            || ! hash_equals(hash_hmac('sha256', $timestamp.'.'.$body, $secret), $signature)) {
            throw new FoundationFailure('callback_rejected', 401, 'verify_callback');
        }
        if ((new FoundationEnvironment)->problems() !== []) {
            throw new FoundationFailure('foundation_configuration_invalid', 503, 'contact_operations');
        }
        $payload = $this->parse($body);
        $digest = FoundationIdempotency::fingerprint($payload);
        $event = FoundationEvent::query()->createOrFirst(['identity_hash' => hash('sha256', $source.'|'.$payload['event_id'])], [
            'source' => $source, 'external_event_id' => $payload['event_id'],
            'id' => (string) Str::uuid(), 'payload_hash' => $digest, 'payload' => $payload,
            'state' => 'pending', 'attempts' => 0, 'available_at' => now(),
        ]);
        if (! hash_equals($event->payload_hash, $digest)) {
            $this->audit->record(null, 'callback.conflict', $event->id, 'idempotency_conflict', context: ['event_reference' => $event->id]);
            throw new FoundationFailure('idempotency_conflict', 409, 'retain_original_event');
        }

        return $event;
    }

    public function process(string $id): void
    {
        $token = $this->claims->acquire(new FoundationEvent, $id);
        if ($token === null) {
            return;
        }
        $event = FoundationEvent::query()->findOrFail($id);
        try {
            DB::transaction(function () use ($id, $token): void {
                $event = FoundationEvent::query()->whereKey($id)->where('state', 'processing')->where('lease_token', $token)->lockForUpdate()->first();
                if ($event === null || $event->lease_expires_at <= now()) {
                    return;
                }
                $payload = $event->payload;
                $observed = (new DateTimeImmutable($payload['occurred_at']))->setTimezone(new DateTimeZone('UTC'));
                $operation = FoundationOperation::query()->createOrFirst(['identity_hash' => hash('sha256', $event->source.'|'.$payload['operation_reference'])], [
                    'source' => $event->source, 'reference' => $payload['operation_reference'],
                    'state' => 'pending', 'observed_at' => $observed, 'last_event_id' => $id,
                ]);
                $operation = FoundationOperation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail();
                if ($observed >= $operation->observed_at) {
                    $terminal = in_array($operation->state, ['succeeded', 'failed', 'reversed'], true);
                    if ($terminal && $payload['status'] !== $operation->state && ! ($operation->state === 'succeeded' && $payload['status'] === 'reversed')) {
                        $this->cases->open('integration', $id);
                    } else {
                        $operation->update(['state' => $payload['status'], 'observed_at' => $observed, 'last_event_id' => $id]);
                    }
                }
                $this->audit->record(null, 'integration.observed', $id, 'verified_callback', context: ['event_reference' => $id, 'state' => $payload['status']]);
                $changed = FoundationEvent::query()->whereKey($id)->where('state', 'processing')->where('lease_token', $token)
                    ->update(['state' => 'processed', 'completed_at' => now(), 'lease_token' => null, 'lease_expires_at' => null, 'last_error_code' => null]);
                if ($changed !== 1) {
                    throw new FoundationFailure('stale_worker');
                }
            }, attempts: 5);
        } catch (Throwable) {
            $state = $event->fresh()->attempts >= config('pull2spend.event_attempts') ? 'failed' : 'pending';
            DB::transaction(function () use ($event, $token, $state): void {
                if ($this->claims->release($event, $token, 'processing_failed', $state) && $state === 'failed') {
                    $this->cases->open('integration', $event->id);
                }
            });
        }
    }

    /** @return array{event_id: string, operation_reference: string, status: string, occurred_at: string} */
    private function parse(string $body): array
    {
        try {
            $data = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new FoundationFailure('invalid_callback', 422, 'correct_schema');
        }
        $keys = ['event_id', 'operation_reference', 'status', 'occurred_at'];
        if (! is_array($data) || array_diff(array_keys($data), $keys) !== [] || array_diff($keys, array_keys($data)) !== []) {
            throw new FoundationFailure('invalid_callback', 422, 'correct_schema');
        }
        foreach (['event_id', 'operation_reference'] as $field) {
            if (! is_string($data[$field]) || preg_match('/\A[A-Za-z0-9_.:-]{1,128}\z/', $data[$field]) !== 1) {
                throw new FoundationFailure('invalid_callback', 422, 'correct_schema');
            }
        }
        if (! in_array($data['status'], ['pending', 'unknown', 'succeeded', 'failed', 'reversed'], true)
            || ! is_string($data['occurred_at']) || preg_match('/\A(?!0000)\d{4}-\d{2}-\d{2}T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)\z/', $data['occurred_at']) !== 1) {
            throw new FoundationFailure('invalid_callback', 422, 'correct_schema');
        }
        try {
            $date = new DateTimeImmutable($data['occurred_at']);
        } catch (\Exception) {
            throw new FoundationFailure('invalid_callback', 422, 'correct_schema');
        }
        if (DateTimeImmutable::getLastErrors() !== false || $date->getTimestamp() > now()->timestamp + config('pull2spend.callback_skew_seconds')) {
            throw new FoundationFailure('invalid_callback', 422, 'correct_schema');
        }

        return $data;
    }
}

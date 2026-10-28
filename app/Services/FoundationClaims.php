<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ConsumerOtpDelivery;
use App\Models\FoundationDelivery;
use App\Models\FoundationEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/** Database claims, rather than queue reservations, fence concurrent/restarted workers. */
final class FoundationClaims
{
    /** @return Builder<FoundationEvent>|Builder<FoundationDelivery> */
    public function due(FoundationEvent|FoundationDelivery|ConsumerOtpDelivery $model): Builder
    {
        $pendingStates = $model instanceof FoundationDelivery ? ['pending', 'unknown'] : ['pending'];

        return $model->newQuery()->where('attempts', '<', config('pull2spend.event_attempts'))
            ->where(function (Builder $query) use ($pendingStates): void {
                $query->where(function (Builder $pending) use ($pendingStates): void {
                    $pending->whereIn('state', $pendingStates)->where('available_at', '<=', now());
                })->orWhere(function (Builder $expired): void {
                    $expired->where('state', 'processing')->where('lease_expires_at', '<=', now());
                });
            });
    }

    public function acquire(FoundationEvent|FoundationDelivery|ConsumerOtpDelivery $model, string $id): ?string
    {
        $token = (string) Str::uuid();
        $changed = $this->due($model)->whereKey($id)->increment('attempts', 1, [
            'state' => 'processing', 'lease_token' => $token,
            'lease_expires_at' => now()->addSeconds((int) config('pull2spend.lease_seconds')),
            'updated_at' => now(),
        ]);

        return $changed === 1 ? $token : null;
    }

    public function release(FoundationEvent|FoundationDelivery|ConsumerOtpDelivery $record, string $token, string $errorCode, string $state = 'pending'): bool
    {
        $attempt = $record->fresh()->attempts;
        $delays = config('pull2spend.retry_seconds');

        return $record->newQuery()->whereKey($record->getKey())->where('lease_token', $token)
            ->where('state', 'processing')->update([
                'state' => $state,
                'last_error_code' => $errorCode,
                'available_at' => now()->addSeconds($delays[min($attempt - 1, count($delays) - 1)]),
                'lease_token' => null, 'lease_expires_at' => null, 'updated_at' => now(),
            ]) === 1;
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\DeliverFoundationNotification;
use App\Jobs\ProcessFoundationEvent;
use App\Models\FoundationDelivery;
use App\Models\FoundationEvent;
use App\Services\CaseService;
use App\Services\FoundationClaims;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecoverFoundationWork extends Command
{
    protected $signature = 'foundation:recover';

    protected $description = 'Redispatch due committed foundation work, independent of admission flags';

    public function handle(FoundationClaims $claims, CaseService $cases): int
    {
        foreach ([new FoundationEvent, new FoundationDelivery] as $model) {
            $exhausted = $model->newQuery()->where('state', 'processing')->where('lease_expires_at', '<=', now())
                ->where('attempts', '>=', config('pull2spend.event_attempts'))->limit(100)->get();
            foreach ($exhausted as $record) {
                DB::transaction(function () use ($record, $cases): void {
                    $changed = $record->newQuery()->whereKey($record->id)->where('state', 'processing')->where('lease_expires_at', '<=', now())
                        ->update(['state' => $record instanceof FoundationDelivery ? 'unknown' : 'failed', 'last_error_code' => 'attempts_exhausted']);
                    if ($changed === 1) {
                        $cases->open($record instanceof FoundationEvent ? 'integration' : 'notification', $record->id, customerId: $record instanceof FoundationDelivery ? $record->user_id : null);
                    }
                });
            }
            foreach ($claims->due($model)->orderBy('available_at')->orderBy('id')->limit(100)->pluck('id') as $id) {
                if ($model instanceof FoundationEvent) {
                    ProcessFoundationEvent::dispatch($id)->onQueue(config('queue.connections.database.queue'));
                } else {
                    DeliverFoundationNotification::dispatch($id)->onQueue(config('queue.connections.database.queue').'.notifications');
                }
            }
        }
        $this->info('Due committed work dispatched; terminal and unknown work retained for review.');

        return self::SUCCESS;
    }
}

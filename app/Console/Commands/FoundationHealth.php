<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\FoundationDelivery;
use App\Models\FoundationEvent;
use App\Models\OperationalCase;
use App\Services\FoundationEnvironment;
use Illuminate\Console\Command;
use Throwable;

class FoundationHealth extends Command
{
    protected $signature = 'foundation:health';

    protected $description = 'Report safe foundation backlog/configuration metrics for an operator';

    public function handle(FoundationEnvironment $environment): int
    {
        $problems = $environment->problems();
        $metrics = ['configuration_problems' => $problems];
        try {
            foreach (['events' => new FoundationEvent, 'deliveries' => new FoundationDelivery] as $name => $model) {
                foreach (['pending', 'processing', 'failed', 'unknown'] as $state) {
                    $metrics[$name.'_'.$state] = $model->newQuery()->where('state', $state)->count();
                }
                $oldest = $model->newQuery()->whereIn('state', ['pending', 'processing'])->min('created_at');
                $metrics[$name.'_oldest_at'] = $oldest;
            }
            $metrics['open_cases'] = OperationalCase::query()->whereNotIn('state', ['resolved', 'closed'])->count();
            $metrics['database_available'] = true;
        } catch (Throwable) {
            $metrics['database_available'] = false;
            $problems[] = 'foundation_storage_unavailable';
        }
        $metrics['configuration_problems'] = $problems;
        $this->line(json_encode($metrics, JSON_THROW_ON_ERROR));

        return $problems === [] ? self::SUCCESS : self::FAILURE;
    }
}

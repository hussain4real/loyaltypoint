<?php

declare(strict_types=1);

use App\Models\FoundationEvent;
use App\Services\FoundationEnvironment;
use App\Services\FoundationFailure;
use App\Services\SimulatedNotificationGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

it('reports unsafe staging configuration without exposing secret values', function (): void {
    app()->detectEnvironment(fn () => 'staging');
    config(['app.debug' => true, 'pull2spend.namespace' => 'staging', 'pull2spend.simulators_enabled' => true, 'pull2spend.callbacks_enabled' => true,
        'session.secure' => false, 'session.encrypt' => false, 'queue.default' => 'sync',
        'cache.prefix' => 'production_cache', 'session.cookie' => 'production_cookie', 'queue.connections.database.queue' => 'production',
        'pull2spend.secret_store_reference' => '', 'pull2spend.backup_evidence_reference' => '', 'pull2spend.alert_owner_reference' => '']);

    expect(app(FoundationEnvironment::class)->problems())->toContain('debug_enabled', 'sandbox_adapter_outside_development', 'session_protection_missing',
        'durable_queue_missing', 'cache_prefix_not_namespaced', 'session_cookie_not_namespaced', 'queue_connections_database_queue_not_namespaced',
        'secret_store_reference_missing', 'backup_evidence_reference_missing', 'alert_owner_reference_missing');
});

it('accepts the isolated staging configuration contract without claiming hosted readiness', function (): void {
    app()->detectEnvironment(fn () => 'staging');
    config(['app.debug' => false, 'pull2spend.namespace' => 'staging', 'pull2spend.simulators_enabled' => false, 'pull2spend.callbacks_enabled' => false,
        'session.secure' => true, 'session.encrypt' => true, 'queue.default' => 'database',
        'cache.prefix' => 'pull2spend_staging_cache', 'session.cookie' => 'pull2spend_staging_session', 'queue.connections.database.queue' => 'pull2spend_staging_foundation',
        'pull2spend.secret_store_reference' => 'secret-store:staging', 'pull2spend.backup_evidence_reference' => 'backup:staging', 'pull2spend.alert_owner_reference' => 'operations:staging']);

    expect(app(FoundationEnvironment::class)->problems())->toBe([]);
});

it('rejects a simulator outside local and testing environments', function (): void {
    app()->detectEnvironment(fn () => 'production');
    config(['pull2spend.simulators_enabled' => true]);

    expect(fn () => app(SimulatedNotificationGateway::class)->status('delivery:1'))->toThrow(FoundationFailure::class, 'delivery_unavailable');
});

it('requires namespace and worker timeout ordering', function (): void {
    config(['pull2spend.namespace' => '../shared', 'pull2spend.lease_seconds' => 30]);
    expect(app(FoundationEnvironment::class)->problems())->toContain('environment_namespace_missing', 'unsafe_worker_timeout_order');
});

it('rejects invalid retry schedules and callback limits before accepting new work', function (): void {
    config(['pull2spend.event_attempts' => 0, 'pull2spend.retry_seconds' => [], 'pull2spend.callback_max_bytes' => 0, 'pull2spend.callback_skew_seconds' => -1]);
    expect(app(FoundationEnvironment::class)->problems())->toContain('invalid_retry_policy', 'invalid_callback_max_bytes', 'invalid_callback_skew_seconds');
});

it('reports backlog counts and safe configuration failures without raw event data', function (): void {
    config(['pull2spend.namespace' => 'testing']);
    FoundationEvent::factory()->create(['payload' => ['secret' => 'must-not-appear']]);
    expect(Artisan::call('foundation:health'))->toBe(0);
    $output = Artisan::output();
    expect(json_decode($output, true, flags: JSON_THROW_ON_ERROR))->toMatchArray(['events_pending' => 1, 'database_available' => true, 'configuration_problems' => []]);
    expect($output)->not->toContain('must-not-appear');

    config(['pull2spend.namespace' => '../shared']);
    expect(Artisan::call('foundation:health'))->toBe(1);
    expect(Artisan::output())->toContain('environment_namespace_missing')->not->toContain('must-not-appear');
});

it('rejects keyed retry schedules that cannot be indexed by attempt number', function (): void {
    config(['pull2spend.retry_seconds' => [1 => 5, 2 => 30]]);
    expect(app(FoundationEnvironment::class)->problems())->toContain('invalid_retry_policy');
});

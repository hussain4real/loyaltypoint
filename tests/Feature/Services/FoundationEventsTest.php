<?php

declare(strict_types=1);

use App\Jobs\DeliverFoundationNotification;
use App\Jobs\ProcessFoundationEvent;
use App\Models\AuditEvent;
use App\Models\FoundationDelivery;
use App\Models\FoundationEvent;
use App\Models\OperationalCase;
use App\Models\User;
use App\Services\AuditService;
use App\Services\FoundationClaims;
use App\Services\FoundationEvents;
use App\Services\FoundationFailure;
use App\Services\FoundationIdempotency;
use App\Services\FoundationNotifications;
use App\Services\NotificationGateway;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['pull2spend.callbacks_enabled' => true, 'pull2spend.simulators_enabled' => true, 'pull2spend.namespace' => 'testing',
        'pull2spend.callback_sources.sandbox-partner.secret' => str_repeat('test-key', 8)]);
});

function foundationBody(array $changes = []): string
{
    return json_encode(array_replace(['event_id' => 'event:1', 'operation_reference' => 'operation:1', 'status' => 'succeeded', 'occurred_at' => now()->toIso8601String()], $changes), JSON_THROW_ON_ERROR);
}

function foundationHeaders(string $body, ?string $timestamp = null): array
{
    $timestamp ??= (string) now()->timestamp;

    return ['X-Callback-Timestamp' => $timestamp, 'X-Callback-Signature' => hash_hmac('sha256', $timestamp.'.'.$body, config('pull2spend.callback_sources.sandbox-partner.secret'))];
}

function acceptFoundationBody(string $body): FoundationEvent
{
    $headers = foundationHeaders($body);

    return app(FoundationEvents::class)->accept('sandbox-partner', $body, $headers['X-Callback-Timestamp'], $headers['X-Callback-Signature']);
}

it('returns 202 with a durable encrypted receipt without running a handler', function (): void {
    $this->freezeTime();
    $body = foundationBody();
    $response = $this->call('POST', '/api/v2/foundation/callbacks/sandbox-partner', server: ['HTTP_X_CALLBACK_TIMESTAMP' => foundationHeaders($body)['X-Callback-Timestamp'], 'HTTP_X_CALLBACK_SIGNATURE' => foundationHeaders($body)['X-Callback-Signature'], 'HTTP_ACCEPT' => 'application/json'], content: $body);

    $response->assertAccepted()->assertJsonPath('status', 'accepted');
    $this->assertDatabaseHas('foundation_events', ['id' => $response->json('reference'), 'state' => 'pending']);
    $this->assertDatabaseCount('foundation_operations', 0);
    expect(DB::table('foundation_events')->value('payload'))->not->toContain('operation:1');
});

it('returns 401 for invalid or stale callback authentication', function (string $problem): void {
    $this->freezeTime();
    $body = foundationBody();
    $headers = foundationHeaders($body, $problem === 'stale' ? (string) (now()->timestamp - 301) : null);
    if ($problem === 'signature') {
        $headers['X-Callback-Signature'] = 'invalid';
    }
    $this->call('POST', '/api/v2/foundation/callbacks/sandbox-partner', server: ['HTTP_X_CALLBACK_TIMESTAMP' => $headers['X-Callback-Timestamp'], 'HTTP_X_CALLBACK_SIGNATURE' => $headers['X-Callback-Signature'], 'HTTP_ACCEPT' => 'application/json'], content: $body)
        ->assertUnauthorized()->assertJsonPath('error.code', 'callback_rejected');
    $this->assertDatabaseCount('foundation_events', 0);
})->with(['signature', 'stale']);

it('rejects invalid normalized event data without persistence', function (array $changes): void {
    $this->freezeTime();
    expect(fn () => acceptFoundationBody(foundationBody($changes)))->toThrow(FoundationFailure::class, 'invalid_callback');
    $this->assertDatabaseCount('foundation_events', 0);
})->with([
    'unexpected sensitive field' => [['secret' => 'must-not-persist']],
    'unsupported status' => [['status' => 'approved']],
    'array reference' => [['operation_reference' => []]],
    'invalid offset hour' => [['occurred_at' => '2026-10-26T10:00:00+24:00']],
    'invalid offset minute' => [['occurred_at' => '2026-10-26T10:00:00+03:60']],
    'invalid zero year' => [['occurred_at' => '0000-01-01T00:00:00Z']],
    'invalid date' => [['occurred_at' => '2026-02-30T12:00:00Z']],
    'future date' => [['occurred_at' => '2099-01-01T00:00:00Z']],
]);

it('deduplicates identical events and rejects conflicting payloads with 409 semantics', function (): void {
    $this->freezeTime();
    $first = acceptFoundationBody(foundationBody());
    $second = acceptFoundationBody(foundationBody());

    expect($second->id)->toBe($first->id);
    expect(fn () => acceptFoundationBody(foundationBody(['status' => 'failed'])))->toThrow(FoundationFailure::class, 'idempotency_conflict');
    $this->assertDatabaseCount('foundation_events', 1);
    $this->assertDatabaseHas('audit_events', ['action' => 'callback.conflict']);
});

it('processes a repeated callback once and atomically retains its observation', function (): void {
    $this->freezeTime();
    $event = acceptFoundationBody(foundationBody());
    app(FoundationEvents::class)->process($event->id);
    app(FoundationEvents::class)->process($event->id);

    $this->assertDatabaseHas('foundation_events', ['id' => $event->id, 'state' => 'processed', 'attempts' => 1]);
    $this->assertDatabaseHas('foundation_operations', ['reference' => 'operation:1', 'state' => 'succeeded']);
    expect(AuditEvent::query()->where('action', 'integration.observed')->count())->toBe(1);
});

it('rolls back interrupted handler effects and retries safely', function (): void {
    $this->freezeTime();
    $event = acceptFoundationBody(foundationBody());
    $this->mock(AuditService::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('secret transport details'));

    app(FoundationEvents::class)->process($event->id);

    $this->assertDatabaseCount('foundation_operations', 0);
    $this->assertDatabaseHas('foundation_events', ['id' => $event->id, 'state' => 'pending', 'last_error_code' => 'processing_failed']);
    app()->instance(AuditService::class, new AuditService);
    $this->travel(6)->seconds();
    app(FoundationEvents::class)->process($event->id);
    $this->assertDatabaseHas('foundation_events', ['id' => $event->id, 'state' => 'processed', 'attempts' => 2]);
    $this->assertDatabaseCount('foundation_operations', 1);
});

it('retains terminal state and creates an exception case for contradictory newer events', function (): void {
    $this->freezeTime();
    $first = acceptFoundationBody(foundationBody());
    app(FoundationEvents::class)->process($first->id);
    $this->travel(1)->seconds();
    $second = acceptFoundationBody(foundationBody(['event_id' => 'event:2', 'status' => 'failed']));
    app(FoundationEvents::class)->process($second->id);

    $this->assertDatabaseHas('foundation_operations', ['state' => 'succeeded']);
    $this->assertDatabaseHas('operational_cases', ['category' => 'integration', 'source_reference' => $second->id]);
});

it('does not regress an operation from a reordered older event', function (): void {
    $this->freezeTime();
    $first = acceptFoundationBody(foundationBody());
    app(FoundationEvents::class)->process($first->id);
    $second = acceptFoundationBody(foundationBody(['event_id' => 'event:2', 'status' => 'pending', 'occurred_at' => now()->subMinute()->toIso8601String()]));
    app(FoundationEvents::class)->process($second->id);

    $this->assertDatabaseHas('foundation_operations', ['state' => 'succeeded', 'last_event_id' => $first->id]);
    $this->assertDatabaseCount('operational_cases', 0);
});

it('fences stale claims and recovers an abandoned processing lease', function (): void {
    $this->freezeTime();
    $event = FoundationEvent::factory()->create();
    $claims = app(FoundationClaims::class);
    $oldToken = $claims->acquire(new FoundationEvent, $event->id);
    expect($claims->acquire(new FoundationEvent, $event->id))->toBeNull();
    $this->travel(61)->seconds();
    $newToken = $claims->acquire(new FoundationEvent, $event->id);
    expect($newToken)->not->toBe($oldToken);
    expect($claims->release($event, $oldToken, 'old_worker'))->toBeFalse();
    expect($claims->release($event, $newToken, 'retry'))->toBeTrue();
    $this->travel(31)->seconds();
    app(FoundationEvents::class)->process($event->id);
    $this->assertDatabaseHas('foundation_events', ['id' => $event->id, 'state' => 'processed']);
});

it('recovers committed work while new admissions are disabled', function (): void {
    $this->freezeTime();
    Queue::fake([ProcessFoundationEvent::class, DeliverFoundationNotification::class]);
    $event = FoundationEvent::factory()->create();
    $delivery = FoundationDelivery::factory()->create();
    config(['pull2spend.callbacks_enabled' => false, 'queue.connections.database.queue' => 'testing_foundation']);

    $this->artisan('foundation:recover')->assertSuccessful();

    Queue::assertPushed(ProcessFoundationEvent::class, fn ($job) => $job->eventId === $event->id && $job->queue === 'testing_foundation');
    Queue::assertPushed(DeliverFoundationNotification::class, fn ($job) => $job->deliveryId === $delivery->id && $job->queue === 'testing_foundation.notifications');
    expect(fn () => acceptFoundationBody(foundationBody()))->toThrow(FoundationFailure::class, 'capability_disabled');
    app(FoundationEvents::class)->process($event->id);
    $this->assertDatabaseHas('foundation_events', ['id' => $event->id, 'state' => 'processed']);
});

it('creates one owned case when a crashed final attempt exhausts recovery', function (): void {
    $this->freezeTime();
    $event = FoundationEvent::factory()->create(['state' => 'processing', 'attempts' => 5, 'lease_expires_at' => now()->subSecond()]);
    $this->artisan('foundation:recover')->assertSuccessful();
    $this->artisan('foundation:recover')->assertSuccessful();

    $this->assertDatabaseHas('foundation_events', ['id' => $event->id, 'state' => 'failed']);
    $this->assertDatabaseCount('operational_cases', 1);
    expect(OperationalCase::query()->first()->owner_team)->toBe('operations');
});

it('deduplicates notification requests and simulator delivery receipts', function (): void {
    $this->freezeTime();
    $user = User::factory()->create();
    $service = app(FoundationNotifications::class);
    $first = $service->enqueue($user->id, 'operation_status', 'operation:1');
    $second = $service->enqueue($user->id, 'operation_status', 'operation:1');
    $service->deliver($first->id);
    $service->deliver($first->id);

    expect($second->id)->toBe($first->id);
    $this->assertDatabaseHas('foundation_deliveries', ['id' => $first->id, 'state' => 'sent', 'attempts' => 1]);
    $this->assertDatabaseCount('foundation_delivery_receipts', 1);
});

it('keeps notification uncertainty separate from a completed integration event', function (): void {
    $this->freezeTime();
    $event = acceptFoundationBody(foundationBody());
    app(FoundationEvents::class)->process($event->id);
    $delivery = FoundationDelivery::factory()->create();
    $gateway = $this->mock(NotificationGateway::class);
    $gateway->shouldReceive('status')->once()->andReturnNull();
    $gateway->shouldReceive('send')->once()->andThrow(new RuntimeException('uncertain acknowledgement'));

    app(FoundationNotifications::class)->deliver($delivery->id);

    $this->assertDatabaseHas('foundation_events', ['id' => $event->id, 'state' => 'processed']);
    $this->assertDatabaseHas('foundation_deliveries', ['id' => $delivery->id, 'state' => 'unknown', 'last_error_code' => 'delivery_unknown']);
    $this->assertDatabaseHas('operational_cases', ['category' => 'notification', 'source_reference' => $delivery->id]);
});

it('uses actor and operation scoped idempotency keys and rejects floating amounts', function (): void {
    expect(FoundationIdempotency::fingerprint(['b' => '350.00', 'a' => 10000]))->toBe(FoundationIdempotency::fingerprint(['a' => 10000, 'b' => '350.00']));
    expect(FoundationIdempotency::key(1, 'quote', 'operation-123'))->not->toBe(FoundationIdempotency::key(2, 'quote', 'operation-123'));
    expect(FoundationIdempotency::key(1, 'quote', 'operation-123'))->not->toBe(FoundationIdempotency::key(1, 'confirm', 'operation-123'));
    expect(fn () => FoundationIdempotency::fingerprint(['amount' => 350.0]))->toThrow(InvalidArgumentException::class);
    expect(fn () => FoundationIdempotency::key(1, 'quote', 'short'))->toThrow(FoundationFailure::class);
});

it('queries an uncertain delivery before recovery and never blindly resends', function (): void {
    $this->freezeTime();
    $delivery = FoundationDelivery::factory()->create(['state' => 'unknown', 'attempts' => 1]);
    $gateway = $this->mock(NotificationGateway::class);
    $gateway->shouldReceive('status')->once()->with($delivery->id)->andReturn('provider:confirmed');
    $gateway->shouldNotReceive('send');

    app(FoundationNotifications::class)->deliver($delivery->id);

    $this->assertDatabaseHas('foundation_deliveries', ['id' => $delivery->id, 'state' => 'sent', 'attempts' => 2, 'provider_reference' => 'provider:confirmed']);
});

it('bounds notification retries and retains an owned case without altering completed work', function (): void {
    $this->freezeTime();
    config(['pull2spend.simulators_enabled' => false, 'pull2spend.event_attempts' => 2]);
    $delivery = FoundationDelivery::factory()->create();
    app(FoundationNotifications::class)->deliver($delivery->id);
    $this->travel(6)->seconds();
    app(FoundationNotifications::class)->deliver($delivery->id);
    $this->travel(31)->seconds();
    app(FoundationNotifications::class)->deliver($delivery->id);

    $this->assertDatabaseHas('foundation_deliveries', ['id' => $delivery->id, 'state' => 'failed', 'attempts' => 2]);
    $this->assertDatabaseHas('operational_cases', ['category' => 'notification', 'source_reference' => $delivery->id, 'customer_id' => $delivery->user_id]);
});

it('keeps case-distinct opaque event and operation references separate across database collations', function (): void {
    $this->freezeTime();
    $upper = acceptFoundationBody(foundationBody(['event_id' => 'Event:A', 'operation_reference' => 'Operation:A']));
    $lower = acceptFoundationBody(foundationBody(['event_id' => 'Event:a', 'operation_reference' => 'Operation:a']));
    app(FoundationEvents::class)->process($upper->id);
    app(FoundationEvents::class)->process($lower->id);

    expect($upper->id)->not->toBe($lower->id);
    $this->assertDatabaseCount('foundation_events', 2);
    $this->assertDatabaseCount('foundation_operations', 2);
});

it('consumes a durable database queue job after scheduled redispatch', function (): void {
    $this->freezeTime();
    config(['queue.default' => 'database', 'queue.connections.database.queue' => 'testing_foundation', 'queue.connections.database.after_commit' => false]);
    $event = FoundationEvent::factory()->create();
    $this->artisan('foundation:recover')->assertSuccessful();
    $this->assertDatabaseHas('jobs', ['queue' => 'testing_foundation']);

    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--queue' => 'testing_foundation', '--tries' => 1])->assertSuccessful();

    $this->assertDatabaseHas('foundation_events', ['id' => $event->id, 'state' => 'processed']);
    $this->assertDatabaseCount('jobs', 0);
});

it('does not retain notification work when its enclosing domain transaction rolls back', function (): void {
    $this->freezeTime();
    $user = User::factory()->create();

    expect(fn () => DB::transaction(function () use ($user): void {
        app(FoundationNotifications::class)->enqueue($user->id, 'operation_status', 'operation:rollback');
        throw new RuntimeException('domain rollback');
    }))->toThrow(RuntimeException::class, 'domain rollback');

    $this->assertDatabaseCount('foundation_deliveries', 0);
    $this->assertDatabaseCount('foundation_delivery_receipts', 0);
});

it('orders equivalent-offset and UTC events by actual instant rather than wall-clock spelling', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-26T12:00:00Z'));
    $first = acceptFoundationBody(foundationBody(['event_id' => 'offset:1', 'status' => 'pending', 'occurred_at' => '2026-10-26T13:00:00+03:00']));
    app(FoundationEvents::class)->process($first->id);
    $newer = acceptFoundationBody(foundationBody(['event_id' => 'offset:2', 'status' => 'succeeded', 'occurred_at' => '2026-10-26T10:01:00Z']));
    app(FoundationEvents::class)->process($newer->id);
    $older = acceptFoundationBody(foundationBody(['event_id' => 'offset:3', 'status' => 'pending', 'occurred_at' => '2026-10-26T17:00:00+08:00']));
    app(FoundationEvents::class)->process($older->id);

    $this->assertDatabaseHas('foundation_operations', ['reference' => 'operation:1', 'state' => 'succeeded', 'last_event_id' => $newer->id, 'observed_at' => '2026-10-26 10:01:00']);
    expect($first->refresh()->payload['occurred_at'])->toBe('2026-10-26T13:00:00+03:00');
});

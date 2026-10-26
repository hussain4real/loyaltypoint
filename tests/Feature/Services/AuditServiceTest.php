<?php

declare(strict_types=1);

use App\Models\AuditEvent;
use App\Models\CaseEvent;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('records only coded actor action reason and scoped evidence', function (): void {
    $actor = User::factory()->create();
    $event = app(AuditService::class)->record($actor->id, 'case.reviewed', 'case-123', 'operational_review', 7, 9, ['state' => 'in_progress', 'evidence_reference' => 'evidence-123']);

    expect($event->fresh())->actor_id->toBe($actor->id)->action->toBe('case.reviewed')->reason->toBe('operational_review')->partner_id->toBe(7)->customer_id->toBe(9);
    expect($event->fresh()->context)->toBe(['state' => 'in_progress', 'evidence_reference' => 'evidence-123']);
    expect(json_encode($event->fresh()->toArray()))->not->toContain($actor->email)->not->toContain($actor->password);
});

test('rejects raw or sensitive audit metadata before persistence', function (string $field): void {
    expect(fn () => app(AuditService::class)->record(null, 'event.received', 'event-123', 'accepted', context: [$field => 'sensitive-data']))
        ->toThrow(InvalidArgumentException::class);
    $this->assertDatabaseCount('audit_events', 0);
})->with(['password', 'token', 'authorization', 'pan', 'cvv', 'email', 'payload', 'before', 'after']);

test('rejects free text and nested data in coded audit fields', function (array $fields): void {
    expect(fn () => app(AuditService::class)->record(null, $fields['action'] ?? 'case.opened', $fields['target'] ?? 'case-123', $fields['reason'] ?? 'investigation_required', context: $fields['context'] ?? []))
        ->toThrow(InvalidArgumentException::class);
    $this->assertDatabaseCount('audit_events', 0);
})->with([
    'raw action' => [['action' => 'Customer alice@example.com viewed']],
    'raw reason' => [['reason' => 'Passport 123456789 pending']],
    'raw target' => [['target' => 'alice@example.com']],
    'nested payload' => [['context' => ['state' => ['secret' => 'sensitive']]]],
    'free text value' => [['context' => ['evidence_reference' => 'customer account contains private data']]],
]);

test('prevents audit or case history model mutation', function (string $model, string $operation): void {
    $record = $model::factory()->create();

    expect(function () use ($record, $operation): void {
        if ($operation === 'update') {
            $record->update(['reason' => 'tampered']);
        } else {
            $record->delete();
        }
    })->toThrow(LogicException::class);
    expect($record->fresh()->reason)->not->toBe('tampered');
})->with([
    [AuditEvent::class, 'update'], [AuditEvent::class, 'delete'],
    [CaseEvent::class, 'update'], [CaseEvent::class, 'delete'],
]);

test('rejects direct database mutation of audit or case history', function (string $model, string $operation): void {
    $record = $model::factory()->create();
    $query = DB::table($record->getTable())->where('id', $record->id);

    expect(function () use ($query, $operation): void {
        if ($operation === 'update') {
            $query->update(['reason' => 'tampered']);
        } else {
            $query->delete();
        }
    })->toThrow(QueryException::class);
    expect($record->fresh())->not->toBeNull();
    expect($record->fresh()->reason)->not->toBe('tampered');
})->with([
    [AuditEvent::class, 'update'], [AuditEvent::class, 'delete'],
    [CaseEvent::class, 'update'], [CaseEvent::class, 'delete'],
]);

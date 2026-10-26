<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CaseEvent;
use App\Models\OperationalCase;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CaseService
{
    public const CATEGORIES = ['kyc', 'redemption', 'card', 'complaint', 'dispute', 'connector', 'reconciliation', 'security', 'integration', 'notification'];

    public const STATES = ['open', 'assigned', 'in_progress', 'awaiting_external', 'resolved', 'closed'];

    private const TRANSITIONS = [
        'open' => ['assigned'],
        'assigned' => ['assigned', 'in_progress', 'awaiting_external'],
        'in_progress' => ['assigned', 'awaiting_external', 'resolved'],
        'awaiting_external' => ['assigned', 'in_progress', 'resolved'],
        'resolved' => ['in_progress', 'closed'],
        'closed' => [],
    ];

    public function __construct(private AuditService $audit, private StaffAccessService $access) {}

    /**
     * System failures may enter the owned operations queue without a consumer session.
     * Staff-created cases require the same scoped authorization as later transitions.
     */
    public function open(string $category, string $reference, ?int $partnerId = null, ?int $customerId = null, ?int $actorId = null, ?int $ownerId = null, ?DateTimeInterface $dueAt = null): OperationalCase
    {
        if (! in_array($category, self::CATEGORIES, true)) {
            throw new InvalidArgumentException('Unsupported case category.');
        }
        AuditService::assertReference($reference);
        if ($actorId !== null) {
            $this->access->authorize(User::findOrFail($actorId), 'cases.manage', $partnerId, $customerId);
        }
        $this->assertOwner($ownerId, $partnerId, $customerId);
        $slaHours = max(1, min(168, (int) config('pull2spend.cases.sla_hours', 24)));

        return DB::transaction(function () use ($category, $reference, $partnerId, $customerId, $actorId, $ownerId, $dueAt, $slaHours): OperationalCase {
            $identity = OperationalCase::identityFor($category, $reference);
            $case = OperationalCase::firstOrCreate(['identity_hash' => $identity], [
                'category' => $category, 'source_reference' => $reference,
                'reference' => (string) Str::ulid(), 'partner_id' => $partnerId, 'customer_id' => $customerId,
                'owner_id' => $ownerId, 'owner_team' => 'operations', 'state' => $ownerId === null ? 'open' : 'assigned',
                'due_at' => $dueAt ?? now()->addHours($slaHours),
            ]);
            if ($case->category !== $category || ! hash_equals($case->source_reference, $reference)
                || $case->partner_id !== $partnerId || $case->customer_id !== $customerId) {
                throw new InvalidArgumentException('Case reference conflicts with its existing scope.');
            }
            if ($case->wasRecentlyCreated) {
                $this->append($case, $actorId, null, 'investigation_required', null);
            }

            return $case;
        });
    }

    public function transition(User $actor, OperationalCase $case, string $state, string $reason, ?string $evidenceReference = null, ?int $ownerId = null): OperationalCase
    {
        AuditService::assertCode($reason);
        if ($evidenceReference !== null) {
            AuditService::assertReference($evidenceReference);
        }

        return DB::transaction(function () use ($actor, $case, $state, $reason, $evidenceReference, $ownerId): OperationalCase {
            $current = OperationalCase::whereKey($case->getKey())->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, 'cases.manage', $current->partner_id, $current->customer_id);
            if (! in_array($state, self::TRANSITIONS[$current->state] ?? [], true)) {
                throw new InvalidArgumentException('The case state transition is not permitted.');
            }
            $from = $current->state;
            $assignedOwnerId = $ownerId ?? $current->owner_id;
            $this->assertOwner($assignedOwnerId, $current->partner_id, $current->customer_id);
            if ($assignedOwnerId === null) {
                throw new InvalidArgumentException('A named eligible owner is required.');
            }
            if ($state !== 'assigned' && $ownerId !== null && $ownerId !== $current->owner_id) {
                throw new InvalidArgumentException('Reassign the case before changing its workflow state.');
            }
            $evidence = $evidenceReference ?? $current->evidence_reference;
            if (in_array($state, ['resolved', 'closed'], true) && $evidence === null) {
                throw new InvalidArgumentException('Resolution and closure require an evidence reference.');
            }
            $current->fill(['state' => $state, 'owner_id' => $assignedOwnerId]);
            if ($state === 'resolved') {
                $current->fill(['resolution_code' => $reason, 'evidence_reference' => $evidence, 'resolved_at' => now()]);
            } elseif ($state === 'closed') {
                $current->fill(['evidence_reference' => $evidence, 'closed_at' => now()]);
            } elseif ($from === 'resolved') {
                $current->fill(['resolution_code' => null, 'evidence_reference' => null, 'resolved_at' => null]);
            }
            $current->save();
            $this->append($current, $actor->id, $from, $reason, $evidenceReference ?? $evidence);

            return $current;
        });
    }

    /** @return Builder<OperationalCase> */
    public function visibleTo(User $actor): Builder
    {
        return $this->access->scopeQuery(OperationalCase::query(), $actor, 'cases.read');
    }

    /** @return array<string, int|string|null|bool> */
    public function maskedView(User $actor, OperationalCase $case): array
    {
        $current = OperationalCase::findOrFail($case->getKey());
        $this->access->authorize($actor, 'cases.read', $current->partner_id, $current->customer_id);
        $this->audit->record($actor->id, 'case.viewed', $current->reference, 'operational_review', $current->partner_id, $current->customer_id);

        return [
            'reference' => $current->reference, 'category' => $current->category, 'state' => $current->state,
            'partner_reference' => $current->partner_id === null ? null : 'partner-'.$current->partner_id,
            'customer_reference' => $current->customer_id === null ? null : 'customer-'.$current->customer_id,
            'owner_reference' => $current->owner_id === null ? $current->owner_team : 'staff-'.$current->owner_id,
            'due_at' => $current->due_at->toIso8601String(), 'overdue' => $current->isOverdue(),
            'resolution_code' => $current->resolution_code,
            'evidence_reference' => $current->evidence_reference,
        ];
    }

    private function assertOwner(?int $ownerId, ?int $partnerId, ?int $customerId): void
    {
        if ($ownerId !== null && ! $this->access->canOwnCase($ownerId, $partnerId, $customerId)) {
            throw new InvalidArgumentException('The case owner is inactive, unpermitted or outside its scope.');
        }
    }

    private function append(OperationalCase $case, ?int $actorId, ?string $from, string $reason, ?string $evidence): void
    {
        CaseEvent::create([
            'operational_case_id' => $case->id, 'actor_id' => $actorId, 'from_state' => $from,
            'to_state' => $case->state, 'reason' => $reason, 'evidence_reference' => $evidence, 'owner_id' => $case->owner_id,
        ]);
        $this->audit->record($actorId, 'case.'.($from === null ? 'opened' : 'transitioned'), $case->reference, $reason, $case->partner_id, $case->customer_id, [
            'case_reference' => $case->reference, 'previous_state' => $from, 'state' => $case->state,
            'owner_id' => $case->owner_id, 'evidence_reference' => $evidence,
        ]);
    }
}

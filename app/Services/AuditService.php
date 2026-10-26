<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditEvent;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AuditService
{
    private const CONTEXT_FIELDS = ['operation_reference', 'event_reference', 'case_reference', 'approval_reference', 'elevation_reference', 'evidence_reference', 'previous_state', 'state', 'role', 'permission', 'owner_id', 'attempts', 'error_code', 'scope_reference'];

    /**
     * Accept only coded reasons and opaque references, never request/partner payloads.
     *
     * @param  array<string, int|string|null>  $context
     */
    public function record(?int $actorId, string $action, string $target, string $reason, ?int $partnerId = null, ?int $customerId = null, array $context = []): AuditEvent
    {
        self::assertCode($action);
        self::assertCode($reason);
        self::assertReference($target);
        foreach ($context as $key => $value) {
            if (! in_array($key, self::CONTEXT_FIELDS, true)) {
                throw new InvalidArgumentException('Unsupported audit context field.');
            }
            if ($value !== null && ! is_int($value) && ! is_string($value)) {
                throw new InvalidArgumentException('Audit context contains unsupported data.');
            }
            if (is_string($value)) {
                self::assertReference($value);
            }
        }

        return AuditEvent::create([
            'reference' => (string) Str::ulid(), 'actor_id' => $actorId, 'action' => $action,
            'target_reference' => $target, 'reason' => $reason, 'partner_id' => $partnerId,
            'customer_id' => $customerId, 'context' => $context,
        ]);
    }

    public static function assertCode(string $value): void
    {
        if (preg_match('/\A[a-z][a-z0-9_.-]{0,99}\z/', $value) !== 1) {
            throw new InvalidArgumentException('A coded action or reason is required.');
        }
    }

    public static function assertReference(string $value): void
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:\/-]{0,179}\z/', $value) !== 1) {
            throw new InvalidArgumentException('An opaque reference is required.');
        }
    }
}

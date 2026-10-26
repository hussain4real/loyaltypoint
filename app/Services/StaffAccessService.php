<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StaffRole;
use App\Models\StaffElevation;
use App\Models\StaffMembership;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class StaffAccessService
{
    public function __construct(private Request $request, private AuditService $audit) {}

    public function authorize(User $actor, string $permission, ?int $partnerId = null, ?int $customerId = null): StaffMembership
    {
        $staff = $this->membership($actor, $permission);
        if ($permission !== 'dashboard.read' && ! $this->withinScope($staff, $partnerId, $customerId)) {
            $this->deny($actor, 'scope_denied');
        }

        if ($permission === 'audit.export' && ! StaffElevation::query()
            ->where('staff_membership_id', $staff->id)
            ->where('permission', $permission)
            ->where('session_binding', $this->sessionBinding())
            ->where('partner_id', $partnerId)->where('customer_id', $customerId)
            ->whereNull('revoked_at')->where('expires_at', '>', now())->exists()) {
            $this->deny($actor, 'elevation_required');
        }

        return $staff;
    }

    /** @param Builder<Model> $query
     * @return Builder<Model>
     */
    public function scopeQuery(Builder $query, User $actor, string $permission = 'cases.read', string $customerColumn = 'customer_id', ?string $partnerColumn = 'partner_id'): Builder
    {
        if (! in_array($customerColumn, ['customer_id', 'user_id'], true) || ! in_array($partnerColumn, ['partner_id', null], true)) {
            throw new InvalidArgumentException('Unsupported staff scope mapping.');
        }
        $staff = $this->membership($actor, $permission);
        if ($staff->partner_ids !== null) {
            if ($partnerColumn === null) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn($partnerColumn, $staff->partner_ids);
            }
        }
        if ($staff->customer_ids !== null) {
            $query->whereIn($customerColumn, $staff->customer_ids);
        }

        return $query;
    }

    public function canOwnCase(int $userId, ?int $partnerId, ?int $customerId): bool
    {
        $staff = StaffMembership::query()->where('user_id', $userId)->where('enabled', true)->first();

        return $staff !== null && in_array('cases.manage', $staff->role->permissions(), true)
            && $this->withinScope($staff, $partnerId, $customerId);
    }

    public function grantElevation(User $actor, StaffMembership $target, string $permission, int $minutes, string $reason, ?int $partnerId = null, ?int $customerId = null): StaffElevation
    {
        $this->authorize($actor, 'elevations.manage', $partnerId, $customerId);
        $target->refresh();
        if ($actor->id === $target->user_id || ! $target->enabled || $permission !== 'audit.export'
            || ! in_array($target->role, [StaffRole::Operations, StaffRole::Compliance, StaffRole::Finance], true)
            || ! $this->withinScope($target, $partnerId, $customerId) || $target->session_binding === null
            || $minutes < 1 || $minutes > (int) config('pull2spend.staff_elevation_max_minutes', 30)
            || ! preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $reason)) {
            $this->deny($actor, 'elevation_denied');
        }

        return DB::transaction(function () use ($actor, $target, $permission, $minutes, $reason, $partnerId, $customerId): StaffElevation {
            $elevation = new StaffElevation([
                'staff_membership_id' => $target->id, 'granted_by' => $actor->id,
                'permission' => $permission, 'partner_id' => $partnerId, 'customer_id' => $customerId,
                'reason' => $reason, 'expires_at' => now()->addMinutes($minutes),
            ]);
            $elevation->session_binding = $target->session_binding;
            $elevation->save();
            $this->audit->record($actor->id, 'staff.elevation_granted', 'staff:'.$target->id, $reason, $partnerId, $customerId, [
                'elevation_reference' => (string) $elevation->id, 'permission' => $permission,
            ]);

            return $elevation;
        });
    }

    public function revokeElevation(User $actor, StaffElevation $elevation, string $reason): void
    {
        $this->authorize($actor, 'elevations.manage', $elevation->partner_id, $elevation->customer_id);
        abort_unless(preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $reason), 422);
        DB::transaction(function () use ($actor, $elevation, $reason): void {
            $elevation->update(['revoked_at' => now()]);
            $this->audit->record($actor->id, 'staff.elevation_revoked', 'staff:'.$elevation->staff_membership_id, $reason,
                $elevation->partner_id, $elevation->customer_id, ['elevation_reference' => (string) $elevation->id]);
        });
    }

    private function membership(User $actor, string $permission): StaffMembership
    {
        if (! app()->environment('local', 'testing') && (new FoundationEnvironment)->problems() !== []) {
            throw new AuthorizationException('Staff configuration requires operational review.');
        }
        if (! config('pull2spend.backoffice_enabled', false) || ! $this->hasRecentMfa($actor)) {
            $this->deny($actor, 'mfa_required');
        }
        $staff = StaffMembership::query()->where('user_id', $actor->id)->where('enabled', true)->first();
        if ($staff === null || ($staff->role === StaffRole::PartnerViewer && $staff->partner_ids === null)
            || (! in_array($permission, $staff->role->permissions(), true)
                && ! ($permission === 'audit.export' && in_array('audit.read', $staff->role->permissions(), true)))) {
            $this->deny($actor, 'permission_denied');
        }

        return $staff;
    }

    public function hasRecentMfa(User $actor): bool
    {
        if (! $this->request->hasSession() || $this->request->user('web')?->id !== $actor->id) {
            return false;
        }
        $session = $this->request->session();
        $verifiedAt = $session->get('staff_mfa_at');
        $staff = StaffMembership::query()->where('user_id', $actor->id)->where('enabled', true)->first();

        return $staff?->mfa_confirmed_at !== null
            && $session->get('staff_mfa_user') === $actor->id
            && is_int($verifiedAt) && $verifiedAt <= now()->timestamp
            && $verifiedAt > now()->timestamp - (int) config('pull2spend.staff_mfa_ttl_seconds', 1800)
            && is_string($session->get('staff_mfa_binding'))
            && hash_equals($this->sessionBinding(), $session->get('staff_mfa_binding'))
            && is_string($staff->session_binding) && hash_equals($staff->session_binding, $this->sessionBinding());
    }

    private function deny(User $actor, string $reason): never
    {
        $this->audit->record($actor->id, 'staff.access_denied', 'backoffice', $reason);

        throw new AuthorizationException('This staff action is not permitted.');
    }

    private function sessionBinding(): string
    {
        return hash('sha256', $this->request->session()->getId());
    }

    private function withinScope(StaffMembership $staff, ?int $partnerId, ?int $customerId): bool
    {
        return ($staff->partner_ids === null || in_array($partnerId, $staff->partner_ids, true))
            && ($staff->customer_ids === null || in_array($customerId, $staff->customer_ids, true));
    }
}

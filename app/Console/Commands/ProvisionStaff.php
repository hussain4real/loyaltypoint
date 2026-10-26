<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\StaffRole;
use App\Models\StaffMembership;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProvisionStaff extends Command
{
    protected $signature = 'staff:provision {email} {role} {--partner=*} {--customer=*} {--global : Permit unrestricted partner/customer scope for a non-partner role} {--reason=access_provisioning}';

    protected $description = 'Provision a staff membership and one-time authenticator invitation through trusted operator access';

    public function handle(AuditService $audit): int
    {
        $role = StaffRole::tryFrom((string) $this->argument('role'));
        $user = User::query()->where('email', $this->argument('email'))->first();
        if ($role === null || $user === null || StaffMembership::query()->where('user_id', $user->id)->exists()
            || ($this->option('global') && $role === StaffRole::PartnerViewer)) {
            $this->error('An existing user, valid role and new permitted membership are required.');

            return self::FAILURE;
        }
        foreach ([...$this->option('partner'), ...$this->option('customer')] as $scopeId) {
            if ((! is_string($scopeId) && ! is_int($scopeId)) || ! preg_match('/\A[0-9]+\z/', (string) $scopeId) || (int) $scopeId < 1) {
                $this->error('Scope IDs must be positive integer identifiers.');

                return self::FAILURE;
            }
        }
        $partners = array_map('intval', $this->option('partner'));
        $customers = array_map('intval', $this->option('customer'));
        if (array_filter([...$partners, ...$customers], fn (int $id): bool => $id < 1)
            || ($role === StaffRole::PartnerViewer && $partners === [])) {
            $this->error('Scope IDs must be positive; a partner viewer requires partner scope.');

            return self::FAILURE;
        }
        $token = Str::random(48);
        DB::transaction(function () use ($role, $user, $partners, $customers, $token, $audit): void {
            $staff = new StaffMembership(['user_id' => $user->id, 'role' => $role,
                'partner_ids' => $this->option('global') ? null : ($partners !== [] ? $partners : ($customers !== [] ? null : [])),
                'customer_ids' => $this->option('global') ? null : ($customers !== [] ? $customers : ($partners !== [] ? null : [])), 'enabled' => true]);
            $staff->enrollment_token_hash = hash('sha256', $token);
            $staff->save();
            $audit->record(null, 'staff.provisioned', 'staff:'.$staff->id, (string) $this->option('reason'), null, null, ['role' => $role->value]);
        });
        $this->warn('Deliver this one-time invitation through an approved private channel. It is not logged by the application.');
        $this->line($token);

        return self::SUCCESS;
    }
}

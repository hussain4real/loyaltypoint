<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\StaffMembership;
use App\Models\User;
use App\Services\StaffMfaService;
use Illuminate\Console\Command;

class ResetStaffMfa extends Command
{
    protected $signature = 'staff:reset-mfa {email} {operator : Independent global operations/compliance user email}';

    protected $description = 'Reset a staff factor after independent operator password and authenticator verification';

    public function handle(StaffMfaService $mfa): int
    {
        $operator = User::query()->where('email', $this->argument('operator'))->firstOrFail();
        $user = User::query()->where('email', $this->argument('email'))->firstOrFail();
        $target = StaffMembership::query()->where('user_id', $user->id)->firstOrFail();
        $password = $this->secret('Operator password');
        $code = $this->secret('Operator authenticator code');
        if (! is_string($password) || ! is_string($code)) {
            return self::FAILURE;
        }
        $token = $mfa->resetEnrollment($operator, $target, $password, $code);
        $this->warn('Deliver this one-time invitation through an approved private channel. All previous factor sessions and elevations are invalid.');
        $this->line($token);

        return self::SUCCESS;
    }
}

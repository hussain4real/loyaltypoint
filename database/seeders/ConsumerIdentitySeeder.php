<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ConsumerIdentity;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/** Local denial-state fixture. Never seeds verification, eligibility or usable credentials. */
class ConsumerIdentitySeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('Consumer fixtures are restricted to local/testing environments.');
        }
        $user = User::firstOrCreate(['email' => 'pending-consumer@example.invalid'], ['name' => 'Pending consumer fixture', 'password' => Hash::make(Str::random(64))]);
        ConsumerIdentity::firstOrCreate(['user_id' => $user->id], ['contact_hash' => hash('sha256', $user->email), 'terms_version' => 'fixture-terms', 'privacy_notice_version' => 'fixture-privacy', 'notices_accepted_at' => now()]);
    }
}

<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\TransactionType;
use App\Models\PointTransaction;
use App\Models\User;
use Illuminate\Database\Seeder;

class PointTransactionSeeder extends Seeder
{
    public function run(): void
    {
        // Create sample users with point transactions
        $users = User::factory()->count(5)->create();

        foreach ($users as $user) {
            $balance = 0;

            // Create earn transactions
            for ($i = 0; $i < rand(3, 8); $i++) {
                $points = rand(50, 500);
                $balance += $points;

                PointTransaction::create([
                    'user_id' => $user->id,
                    'type' => TransactionType::Earn,
                    'points' => $points,
                    'balance_after' => $balance,
                    'description' => 'Purchase #ORD-'.fake()->unique()->numerify('######'),
                    'created_at' => now()->subDays(rand(1, 60)),
                ]);
            }

            // Create some redemptions
            for ($i = 0; $i < rand(1, 3); $i++) {
                $maxDeduct = min($balance, 200);
                if ($maxDeduct < 10) {
                    continue;
                }

                $points = rand(10, $maxDeduct);
                $balance -= $points;

                PointTransaction::create([
                    'user_id' => $user->id,
                    'type' => TransactionType::Redeem,
                    'points' => -$points,
                    'balance_after' => $balance,
                    'description' => 'Reward redemption',
                    'created_at' => now()->subDays(rand(1, 30)),
                ]);
            }

            // Maybe add a bonus
            if (rand(0, 1)) {
                $points = rand(100, 500);
                $balance += $points;

                PointTransaction::create([
                    'user_id' => $user->id,
                    'type' => TransactionType::Bonus,
                    'points' => $points,
                    'balance_after' => $balance,
                    'description' => 'Welcome bonus',
                    'created_at' => now()->subDays(rand(1, 90)),
                ]);
            }
        }
    }
}

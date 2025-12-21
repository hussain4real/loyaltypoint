<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\TransactionType;
use App\Models\PointTransaction;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class PointTransactionSeeder extends Seeder
{
    /**
     * Sample user data for seeding.
     *
     * @var array<int, array{name: string, email: string}>
     */
    private array $users = [
        ['name' => 'Alice Johnson', 'email' => 'alice@example.com'],
        ['name' => 'Bob Smith', 'email' => 'bob@example.com'],
        ['name' => 'Carol Williams', 'email' => 'carol@example.com'],
        ['name' => 'David Brown', 'email' => 'david@example.com'],
        ['name' => 'Emma Davis', 'email' => 'emma@example.com'],
    ];

    /**
     * Sample transaction data for seeding.
     *
     * @var array<int, array{type: TransactionType, points: int, description: string, days_ago: int}>
     */
    private array $transactions = [
        // Alice's transactions
        ['user_index' => 0, 'type' => 'earn', 'points' => 250, 'description' => 'Purchase #ORD-100001', 'days_ago' => 45],
        ['user_index' => 0, 'type' => 'earn', 'points' => 150, 'description' => 'Purchase #ORD-100002', 'days_ago' => 30],
        ['user_index' => 0, 'type' => 'bonus', 'points' => 100, 'description' => 'Welcome bonus', 'days_ago' => 50],
        ['user_index' => 0, 'type' => 'redeem', 'points' => 75, 'description' => 'Reward redemption', 'days_ago' => 15],
        ['user_index' => 0, 'type' => 'earn', 'points' => 300, 'description' => 'Purchase #ORD-100003', 'days_ago' => 5],

        // Bob's transactions
        ['user_index' => 1, 'type' => 'earn', 'points' => 500, 'description' => 'Purchase #ORD-200001', 'days_ago' => 60],
        ['user_index' => 1, 'type' => 'earn', 'points' => 350, 'description' => 'Purchase #ORD-200002', 'days_ago' => 40],
        ['user_index' => 1, 'type' => 'bonus', 'points' => 200, 'description' => 'Loyalty bonus', 'days_ago' => 35],
        ['user_index' => 1, 'type' => 'redeem', 'points' => 150, 'description' => 'Reward redemption', 'days_ago' => 20],
        ['user_index' => 1, 'type' => 'earn', 'points' => 275, 'description' => 'Purchase #ORD-200003', 'days_ago' => 10],
        ['user_index' => 1, 'type' => 'redeem', 'points' => 100, 'description' => 'Reward redemption', 'days_ago' => 3],

        // Carol's transactions
        ['user_index' => 2, 'type' => 'earn', 'points' => 175, 'description' => 'Purchase #ORD-300001', 'days_ago' => 55],
        ['user_index' => 2, 'type' => 'earn', 'points' => 225, 'description' => 'Purchase #ORD-300002', 'days_ago' => 25],
        ['user_index' => 2, 'type' => 'earn', 'points' => 400, 'description' => 'Purchase #ORD-300003', 'days_ago' => 8],

        // David's transactions
        ['user_index' => 3, 'type' => 'bonus', 'points' => 500, 'description' => 'VIP welcome bonus', 'days_ago' => 70],
        ['user_index' => 3, 'type' => 'earn', 'points' => 600, 'description' => 'Purchase #ORD-400001', 'days_ago' => 50],
        ['user_index' => 3, 'type' => 'earn', 'points' => 450, 'description' => 'Purchase #ORD-400002', 'days_ago' => 30],
        ['user_index' => 3, 'type' => 'redeem', 'points' => 200, 'description' => 'Reward redemption', 'days_ago' => 15],
        ['user_index' => 3, 'type' => 'earn', 'points' => 550, 'description' => 'Purchase #ORD-400003', 'days_ago' => 7],
        ['user_index' => 3, 'type' => 'adjustment', 'points' => 50, 'description' => 'Customer service adjustment', 'days_ago' => 2],

        // Emma's transactions
        ['user_index' => 4, 'type' => 'earn', 'points' => 125, 'description' => 'Purchase #ORD-500001', 'days_ago' => 20],
        ['user_index' => 4, 'type' => 'earn', 'points' => 200, 'description' => 'Purchase #ORD-500002', 'days_ago' => 12],
        ['user_index' => 4, 'type' => 'bonus', 'points' => 75, 'description' => 'Referral bonus', 'days_ago' => 10],
        ['user_index' => 4, 'type' => 'redeem', 'points' => 50, 'description' => 'Reward redemption', 'days_ago' => 5],
    ];

    public function run(): void
    {
        // Create users with explicit data
        $createdUsers = [];
        foreach ($this->users as $userData) {
            $createdUsers[] = User::create([
                'name' => $userData['name'],
                'email' => $userData['email'],
                'email_verified_at' => now(),
                'password' => Hash::make('password'),
            ]);
        }

        // Track balances per user
        $balances = array_fill(0, count($createdUsers), 0);

        // Sort transactions by days_ago descending (oldest first)
        $sortedTransactions = $this->transactions;
        usort($sortedTransactions, fn ($a, $b) => $b['days_ago'] <=> $a['days_ago']);

        // Create transactions
        foreach ($sortedTransactions as $txData) {
            $userIndex = $txData['user_index'];
            $user = $createdUsers[$userIndex];
            $type = TransactionType::from($txData['type']);

            $points = match ($type) {
                TransactionType::Redeem => -abs($txData['points']),
                TransactionType::Adjustment => $txData['points'],
                default => abs($txData['points']),
            };

            $balances[$userIndex] += $points;

            PointTransaction::create([
                'user_id' => $user->id,
                'type' => $type,
                'points' => $points,
                'balance_after' => $balances[$userIndex],
                'description' => $txData['description'],
                'created_at' => now()->subDays($txData['days_ago']),
                'updated_at' => now()->subDays($txData['days_ago']),
            ]);
        }
    }
}

<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\TransactionType;
use App\Models\PointTransaction;
use App\Models\Provider;
use App\Models\User;
use App\Models\UserProviderBalance;
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
     * provider_slug references a provider from ProviderSeeder.
     *
     * @var array<int, array{user_index: int, provider_slug: string, type: string, points: int, description: string, days_ago: int}>
     */
    private array $transactions = [
        // Alice's transactions (Loyalty Plus)
        ['user_index' => 0, 'provider_slug' => 'loyalty-plus', 'type' => 'earn', 'points' => 250, 'description' => 'Purchase #ORD-100001', 'days_ago' => 45],
        ['user_index' => 0, 'provider_slug' => 'loyalty-plus', 'type' => 'earn', 'points' => 150, 'description' => 'Purchase #ORD-100002', 'days_ago' => 30],
        ['user_index' => 0, 'provider_slug' => 'loyalty-plus', 'type' => 'bonus', 'points' => 100, 'description' => 'Welcome bonus', 'days_ago' => 50],
        ['user_index' => 0, 'provider_slug' => 'loyalty-plus', 'type' => 'redeem', 'points' => 75, 'description' => 'Reward redemption', 'days_ago' => 15],
        ['user_index' => 0, 'provider_slug' => 'loyalty-plus', 'type' => 'earn', 'points' => 300, 'description' => 'Purchase #ORD-100003', 'days_ago' => 5],
        // Alice also has Rewards Hub points
        ['user_index' => 0, 'provider_slug' => 'rewards-hub', 'type' => 'earn', 'points' => 200, 'description' => 'Partner purchase', 'days_ago' => 20],
        ['user_index' => 0, 'provider_slug' => 'rewards-hub', 'type' => 'bonus', 'points' => 50, 'description' => 'Signup bonus', 'days_ago' => 25],

        // Bob's transactions (Rewards Hub)
        ['user_index' => 1, 'provider_slug' => 'rewards-hub', 'type' => 'earn', 'points' => 500, 'description' => 'Purchase #ORD-200001', 'days_ago' => 60],
        ['user_index' => 1, 'provider_slug' => 'rewards-hub', 'type' => 'earn', 'points' => 350, 'description' => 'Purchase #ORD-200002', 'days_ago' => 40],
        ['user_index' => 1, 'provider_slug' => 'rewards-hub', 'type' => 'bonus', 'points' => 200, 'description' => 'Loyalty bonus', 'days_ago' => 35],
        ['user_index' => 1, 'provider_slug' => 'rewards-hub', 'type' => 'redeem', 'points' => 150, 'description' => 'Reward redemption', 'days_ago' => 20],
        ['user_index' => 1, 'provider_slug' => 'rewards-hub', 'type' => 'earn', 'points' => 275, 'description' => 'Purchase #ORD-200003', 'days_ago' => 10],
        ['user_index' => 1, 'provider_slug' => 'rewards-hub', 'type' => 'redeem', 'points' => 100, 'description' => 'Reward redemption', 'days_ago' => 3],

        // Carol's transactions (Points Express)
        ['user_index' => 2, 'provider_slug' => 'points-express', 'type' => 'earn', 'points' => 175, 'description' => 'Purchase #ORD-300001', 'days_ago' => 55],
        ['user_index' => 2, 'provider_slug' => 'points-express', 'type' => 'earn', 'points' => 225, 'description' => 'Purchase #ORD-300002', 'days_ago' => 25],
        ['user_index' => 2, 'provider_slug' => 'points-express', 'type' => 'earn', 'points' => 400, 'description' => 'Purchase #ORD-300003', 'days_ago' => 8],

        // David's transactions (Bonus Network)
        ['user_index' => 3, 'provider_slug' => 'bonus-network', 'type' => 'bonus', 'points' => 500, 'description' => 'VIP welcome bonus', 'days_ago' => 70],
        ['user_index' => 3, 'provider_slug' => 'bonus-network', 'type' => 'earn', 'points' => 600, 'description' => 'Purchase #ORD-400001', 'days_ago' => 50],
        ['user_index' => 3, 'provider_slug' => 'bonus-network', 'type' => 'earn', 'points' => 450, 'description' => 'Purchase #ORD-400002', 'days_ago' => 30],
        ['user_index' => 3, 'provider_slug' => 'bonus-network', 'type' => 'redeem', 'points' => 200, 'description' => 'Reward redemption', 'days_ago' => 15],
        ['user_index' => 3, 'provider_slug' => 'bonus-network', 'type' => 'earn', 'points' => 550, 'description' => 'Purchase #ORD-400003', 'days_ago' => 7],
        ['user_index' => 3, 'provider_slug' => 'bonus-network', 'type' => 'adjustment', 'points' => 50, 'description' => 'Customer service adjustment', 'days_ago' => 2],
        // David also uses Premium Rewards
        ['user_index' => 3, 'provider_slug' => 'premium-rewards', 'type' => 'earn', 'points' => 1000, 'description' => 'Premium purchase', 'days_ago' => 15],
        ['user_index' => 3, 'provider_slug' => 'premium-rewards', 'type' => 'bonus', 'points' => 500, 'description' => 'VIP tier bonus', 'days_ago' => 10],

        // Emma's transactions (Loyalty Plus)
        ['user_index' => 4, 'provider_slug' => 'loyalty-plus', 'type' => 'earn', 'points' => 125, 'description' => 'Purchase #ORD-500001', 'days_ago' => 20],
        ['user_index' => 4, 'provider_slug' => 'loyalty-plus', 'type' => 'earn', 'points' => 200, 'description' => 'Purchase #ORD-500002', 'days_ago' => 12],
        ['user_index' => 4, 'provider_slug' => 'loyalty-plus', 'type' => 'bonus', 'points' => 75, 'description' => 'Referral bonus', 'days_ago' => 10],
        ['user_index' => 4, 'provider_slug' => 'loyalty-plus', 'type' => 'redeem', 'points' => 50, 'description' => 'Reward redemption', 'days_ago' => 5],
    ];

    public function run(): void
    {
        // Get providers (must run ProviderSeeder first)
        $providers = Provider::all()->keyBy('slug');

        if ($providers->isEmpty()) {
            $this->command->warn('No providers found. Please run ProviderSeeder first.');

            return;
        }

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

        // Track balances per user per provider: [user_index][provider_slug] => balance
        $balances = [];

        // Sort transactions by days_ago descending (oldest first)
        $sortedTransactions = $this->transactions;
        usort($sortedTransactions, fn ($a, $b) => $b['days_ago'] <=> $a['days_ago']);

        // Create transactions
        foreach ($sortedTransactions as $txData) {
            $userIndex = $txData['user_index'];
            $providerSlug = $txData['provider_slug'];
            $user = $createdUsers[$userIndex];
            $provider = $providers[$providerSlug] ?? null;

            if (! $provider) {
                $this->command->warn("Provider '{$providerSlug}' not found, skipping transaction.");

                continue;
            }

            $type = TransactionType::from($txData['type']);

            $points = match ($type) {
                TransactionType::Redeem => -abs($txData['points']),
                TransactionType::Adjustment => $txData['points'],
                default => abs($txData['points']),
            };

            // Initialize balance tracking for this user-provider combination
            $balanceKey = "{$userIndex}_{$providerSlug}";
            if (! isset($balances[$balanceKey])) {
                $balances[$balanceKey] = 0;
            }

            $balances[$balanceKey] += $points;

            PointTransaction::create([
                'user_id' => $user->id,
                'provider_id' => $provider->id,
                'type' => $type,
                'points' => $points,
                'balance_after' => $balances[$balanceKey],
                'description' => $txData['description'],
                'created_at' => now()->subDays($txData['days_ago']),
                'updated_at' => now()->subDays($txData['days_ago']),
            ]);
        }

        // Create UserProviderBalance entries for each user-provider combination
        foreach ($balances as $key => $balance) {
            [$userIndex, $providerSlug] = explode('_', $key, 2);
            $user = $createdUsers[(int) $userIndex];
            $provider = $providers[$providerSlug];

            UserProviderBalance::create([
                'user_id' => $user->id,
                'provider_id' => $provider->id,
                'balance' => $balance,
            ]);
        }
    }
}

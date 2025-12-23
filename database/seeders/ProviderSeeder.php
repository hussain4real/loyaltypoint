<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Provider;
use Illuminate\Database\Seeder;

class ProviderSeeder extends Seeder
{
    /**
     * Explicit provider data for seeding.
     *
     * Points to value ratio examples:
     * - 0.1 means 10 points = $1 (10 × 0.1 = 1)
     * - 1.0 means 1 point = $1 (1 × 1.0 = 1)
     * - 0.01 means 100 points = $1 (100 × 0.01 = 1)
     *
     * @var array<int, array{name: string, trade_name: string|null, slug: string, category: string|null, description: string|null, official_logo: string|null, web_link: string|null, points_to_value_ratio: float, transfer_fee_percent: float}>
     */
    private array $providers = [
        [
            'name' => 'Loyalty Plus',
            'trade_name' => 'Loyalty+',
            'slug' => 'loyalty-plus',
            'category' => 'retail',
            'description' => 'Earn points on every purchase at partner retail stores.',
            'official_logo' => 'https://example.com/logos/loyalty-plus.png',
            'web_link' => 'https://loyaltyplus.example.com',
            'points_to_value_ratio' => 0.1000, // 10 points = $1
            'transfer_fee_percent' => 1.50,
        ],
        [
            'name' => 'Rewards Hub',
            'trade_name' => 'RewardsHub',
            'slug' => 'rewards-hub',
            'category' => 'travel',
            'description' => 'Collect and redeem points for travel and experiences.',
            'official_logo' => 'https://example.com/logos/rewards-hub.png',
            'web_link' => 'https://rewardshub.example.com',
            'points_to_value_ratio' => 1.0000, // 1 point = $1
            'transfer_fee_percent' => 3.50,
        ],
        [
            'name' => 'Points Express',
            'trade_name' => 'PointsX',
            'slug' => 'points-express',
            'category' => 'dining',
            'description' => 'Earn points at participating restaurants and cafes.',
            'official_logo' => 'https://example.com/logos/points-express.png',
            'web_link' => 'https://pointsexpress.example.com',
            'points_to_value_ratio' => 0.0100, // 100 points = $1
            'transfer_fee_percent' => 2.00,
        ],
        [
            'name' => 'Bonus Network',
            'trade_name' => 'BonusNet',
            'slug' => 'bonus-network',
            'category' => 'entertainment',
            'description' => 'Earn bonus points on entertainment and gaming purchases.',
            'official_logo' => 'https://example.com/logos/bonus-network.png',
            'web_link' => 'https://bonusnetwork.example.com',
            'points_to_value_ratio' => 0.5000, // 2 points = $1
            'transfer_fee_percent' => 2.50,
        ],
        [
            'name' => 'Premium Rewards',
            'trade_name' => 'PremiumR',
            'slug' => 'premium-rewards',
            'category' => 'luxury',
            'description' => 'Exclusive rewards program for premium members.',
            'official_logo' => 'https://example.com/logos/premium-rewards.png',
            'web_link' => 'https://premiumrewards.example.com',
            'points_to_value_ratio' => 2.0000, // 1 point = $2
            'transfer_fee_percent' => 1.00,
        ],
    ];

    /**
     * Seed the providers table.
     */
    public function run(): void
    {
        foreach ($this->providers as $providerData) {
            Provider::create([
                'name' => $providerData['name'],
                'trade_name' => $providerData['trade_name'],
                'slug' => $providerData['slug'],
                'category' => $providerData['category'],
                'description' => $providerData['description'],
                'official_logo' => $providerData['official_logo'],
                'web_link' => $providerData['web_link'],
                'is_active' => true,
                'points_to_value_ratio' => $providerData['points_to_value_ratio'],
                'transfer_fee_percent' => $providerData['transfer_fee_percent'],
                'metadata' => null,
            ]);
        }
    }
}

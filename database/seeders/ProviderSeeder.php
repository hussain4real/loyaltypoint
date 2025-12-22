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
     * @var array<int, array{name: string, trade_name: string|null, slug: string, category: string|null, description: string|null, official_logo: string|null, web_link: string|null, exchange_rate_base: float, exchange_fee_percent: float}>
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
            'exchange_rate_base' => 1.0000,
            'exchange_fee_percent' => 0.00,
        ],
        [
            'name' => 'Rewards Hub',
            'trade_name' => 'RewardsHub',
            'slug' => 'rewards-hub',
            'category' => 'travel',
            'description' => 'Collect and redeem points for travel and experiences.',
            'official_logo' => 'https://example.com/logos/rewards-hub.png',
            'web_link' => 'https://rewardshub.example.com',
            'exchange_rate_base' => 1.2500,
            'exchange_fee_percent' => 2.50,
        ],
        [
            'name' => 'Points Express',
            'trade_name' => 'PointsX',
            'slug' => 'points-express',
            'category' => 'dining',
            'description' => 'Earn points at participating restaurants and cafes.',
            'official_logo' => 'https://example.com/logos/points-express.png',
            'web_link' => 'https://pointsexpress.example.com',
            'exchange_rate_base' => 0.8000,
            'exchange_fee_percent' => 1.00,
        ],
        [
            'name' => 'Bonus Network',
            'trade_name' => 'BonusNet',
            'slug' => 'bonus-network',
            'category' => 'entertainment',
            'description' => 'Earn bonus points on entertainment and gaming purchases.',
            'official_logo' => 'https://example.com/logos/bonus-network.png',
            'web_link' => 'https://bonusnetwork.example.com',
            'exchange_rate_base' => 1.5000,
            'exchange_fee_percent' => 3.00,
        ],
        [
            'name' => 'Premium Rewards',
            'trade_name' => 'PremiumR',
            'slug' => 'premium-rewards',
            'category' => 'luxury',
            'description' => 'Exclusive rewards program for premium members.',
            'official_logo' => 'https://example.com/logos/premium-rewards.png',
            'web_link' => 'https://premiumrewards.example.com',
            'exchange_rate_base' => 2.0000,
            'exchange_fee_percent' => 5.00,
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
                'exchange_rate_base' => $providerData['exchange_rate_base'],
                'exchange_fee_percent' => $providerData['exchange_fee_percent'],
                'metadata' => null,
            ]);
        }
    }
}

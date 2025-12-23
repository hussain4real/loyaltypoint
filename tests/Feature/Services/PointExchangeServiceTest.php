<?php

declare(strict_types=1);

use App\Enums\TransactionType;
use App\Models\Provider;
use App\Models\User;
use App\Services\PointExchangeService;
use App\Services\PointService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->exchangeService = app(PointExchangeService::class);
    $this->pointService = app(PointService::class);

    // Set a known app fee for testing
    config(['services.loyalty.app_transfer_fee_percent' => 5.0]);
});

describe('exchange', function (): void {
    it('exchanges points between providers using value-based conversion', function (): void {
        // P1: 10 points = $1 (ratio 0.1), P2: 1 point = $1 (ratio 1.0)
        $user = User::factory()->create();
        $providerA = Provider::factory()->create([
            'points_to_value_ratio' => 0.1,
            'transfer_fee_percent' => 0,
        ]);
        $providerB = Provider::factory()->create([
            'points_to_value_ratio' => 1.0,
            'transfer_fee_percent' => 0,
        ]);

        // Set app fee to 0 for this test
        config(['services.loyalty.app_transfer_fee_percent' => 0]);

        $this->pointService->awardPoints($user, $providerA, 1000, 'Initial balance');

        // 1000 points × 0.1 = $100 value → $100 / 1.0 = 100 points
        $result = $this->exchangeService->exchange($user, $providerA, $providerB, 1000);

        expect($result['points_sent'])->toBe(1000);
        expect($result['gross_value'])->toBe(100.0);
        expect($result['net_value'])->toBe(100.0);
        expect($result['points_received'])->toBe(100);
        expect($user->getBalanceForProvider($providerA))->toBe(0);
        expect($user->getBalanceForProvider($providerB))->toBe(100);
    });

    it('applies all three fees correctly (source, destination, app)', function (): void {
        // P1: ratio 0.1 (10 points = $1), fee 1.5%
        // P2: ratio 1.0 (1 point = $1), fee 3.5%
        // App fee: 5%
        // Total fee: 10%
        $user = User::factory()->create();
        $providerA = Provider::factory()->create([
            'points_to_value_ratio' => 0.1,
            'transfer_fee_percent' => 1.5,
        ]);
        $providerB = Provider::factory()->create([
            'points_to_value_ratio' => 1.0,
            'transfer_fee_percent' => 3.5,
        ]);

        config(['services.loyalty.app_transfer_fee_percent' => 5.0]);

        $this->pointService->awardPoints($user, $providerA, 1000, 'Initial balance');

        // 1000 points × 0.1 = $100 gross value
        // Total fee: 1.5% + 3.5% + 5% = 10%
        // Fee value: $100 × 10% = $10
        // Net value: $100 - $10 = $90
        // Points received: $90 / 1.0 = 90 points
        $result = $this->exchangeService->exchange($user, $providerA, $providerB, 1000);

        expect($result['points_sent'])->toBe(1000);
        expect($result['gross_value'])->toBe(100.0);
        expect($result['total_fee_percent'])->toBe(10.0);
        expect($result['total_fee_value'])->toBe(10.0);
        expect($result['net_value'])->toBe(90.0);
        expect($result['points_received'])->toBe(90);
        expect($user->getBalanceForProvider($providerA))->toBe(0);
        expect($user->getBalanceForProvider($providerB))->toBe(90);
    });

    it('converts between different point value ratios', function (): void {
        // P1: 100 points = $1 (ratio 0.01)
        // P2: 2 points = $1 (ratio 0.5)
        $user = User::factory()->create();
        $providerA = Provider::factory()->create([
            'points_to_value_ratio' => 0.01,
            'transfer_fee_percent' => 0,
        ]);
        $providerB = Provider::factory()->create([
            'points_to_value_ratio' => 0.5,
            'transfer_fee_percent' => 0,
        ]);

        config(['services.loyalty.app_transfer_fee_percent' => 0]);

        $this->pointService->awardPoints($user, $providerA, 10000, 'Initial balance');

        // 10000 points × 0.01 = $100 value → $100 / 0.5 = 200 points
        $result = $this->exchangeService->exchange($user, $providerA, $providerB, 10000);

        expect($result['gross_value'])->toBe(100.0);
        expect($result['points_received'])->toBe(200);
    });

    it('creates transfer transactions with correct types and metadata', function (): void {
        $user = User::factory()->create();
        $providerA = Provider::factory()->create([
            'points_to_value_ratio' => 0.1,
            'transfer_fee_percent' => 2.0,
        ]);
        $providerB = Provider::factory()->create([
            'points_to_value_ratio' => 1.0,
            'transfer_fee_percent' => 3.0,
        ]);

        $this->pointService->awardPoints($user, $providerA, 1000, 'Initial balance');

        $result = $this->exchangeService->exchange($user, $providerA, $providerB, 500);

        // Check transfer out
        expect($result['transfer_out']->type)->toBe(TransactionType::TransferOut);
        expect($result['transfer_out']->points)->toBe(-500);
        expect($result['transfer_out']->provider_id)->toBe($providerA->id);
        expect($result['transfer_out']->metadata)->toHaveKeys([
            'to_provider_id',
            'to_provider_slug',
            'points_sent',
            'gross_value',
            'source_fee_percent',
            'destination_fee_percent',
            'app_fee_percent',
            'total_fee_percent',
            'total_fee_value',
            'net_value',
            'points_received',
        ]);

        // Check transfer in
        expect($result['transfer_in']->type)->toBe(TransactionType::TransferIn);
        expect($result['transfer_in']->points)->toBeGreaterThan(0);
        expect($result['transfer_in']->provider_id)->toBe($providerB->id);
        expect($result['transfer_in']->metadata)->toHaveKeys([
            'from_provider_id',
            'from_provider_slug',
            'original_points',
            'gross_value',
            'total_fee_percent',
            'total_fee_value',
            'net_value',
        ]);
    });

    it('rejects exchange with insufficient balance', function (): void {
        $user = User::factory()->create();
        $providerA = Provider::factory()->create();
        $providerB = Provider::factory()->create();

        $this->pointService->awardPoints($user, $providerA, 100, 'Initial balance');

        $this->exchangeService->exchange($user, $providerA, $providerB, 500);
    })->throws(InvalidArgumentException::class, 'Insufficient points balance.');

    it('rejects exchange within same provider', function (): void {
        $user = User::factory()->create();
        $provider = Provider::factory()->create();

        $this->pointService->awardPoints($user, $provider, 1000, 'Initial balance');

        $this->exchangeService->exchange($user, $provider, $provider, 500);
    })->throws(InvalidArgumentException::class, 'Cannot exchange points within the same provider.');

    it('rejects exchange with inactive source provider', function (): void {
        $user = User::factory()->create();
        $providerA = Provider::factory()->inactive()->create();
        $providerB = Provider::factory()->create();

        $this->exchangeService->exchange($user, $providerA, $providerB, 500);
    })->throws(InvalidArgumentException::class, 'Source provider is not active.');

    it('rejects exchange with inactive destination provider', function (): void {
        $user = User::factory()->create();
        $providerA = Provider::factory()->create();
        $providerB = Provider::factory()->inactive()->create();

        $this->pointService->awardPoints($user, $providerA, 1000, 'Initial balance');

        $this->exchangeService->exchange($user, $providerA, $providerB, 500);
    })->throws(InvalidArgumentException::class, 'Destination provider is not active.');

    it('rejects zero or negative points', function (): void {
        $user = User::factory()->create();
        $providerA = Provider::factory()->create();
        $providerB = Provider::factory()->create();

        $this->exchangeService->exchange($user, $providerA, $providerB, 0);
    })->throws(InvalidArgumentException::class, 'Points must be a positive integer.');

    it('rejects exchange that would result in zero points', function (): void {
        $user = User::factory()->create();
        // Very low value ratio
        $providerA = Provider::factory()->create([
            'points_to_value_ratio' => 0.0001,
            'transfer_fee_percent' => 0,
        ]);
        // Very high value ratio (expensive points)
        $providerB = Provider::factory()->create([
            'points_to_value_ratio' => 100.0,
            'transfer_fee_percent' => 0,
        ]);

        config(['services.loyalty.app_transfer_fee_percent' => 0]);

        $this->pointService->awardPoints($user, $providerA, 1000, 'Initial balance');

        // 10 points × 0.0001 = $0.001 value → $0.001 / 100 = 0.00001 (floors to 0)
        $this->exchangeService->exchange($user, $providerA, $providerB, 10);
    })->throws(InvalidArgumentException::class, 'Exchange would result in zero points. Try a larger amount.');

    it('handles high-value to low-value conversion correctly', function (): void {
        // P1: 1 point = $2 (ratio 2.0)
        // P2: 100 points = $1 (ratio 0.01)
        $user = User::factory()->create();
        $providerA = Provider::factory()->create([
            'points_to_value_ratio' => 2.0,
            'transfer_fee_percent' => 0,
        ]);
        $providerB = Provider::factory()->create([
            'points_to_value_ratio' => 0.01,
            'transfer_fee_percent' => 0,
        ]);

        config(['services.loyalty.app_transfer_fee_percent' => 0]);

        $this->pointService->awardPoints($user, $providerA, 100, 'Initial balance');

        // 100 points × 2.0 = $200 value → $200 / 0.01 = 20000 points
        $result = $this->exchangeService->exchange($user, $providerA, $providerB, 100);

        expect($result['gross_value'])->toBe(200.0);
        expect($result['points_received'])->toBe(20000);
    });
});

describe('preview', function (): void {
    it('returns detailed preview without executing exchange', function (): void {
        $user = User::factory()->create();
        $providerA = Provider::factory()->create([
            'points_to_value_ratio' => 0.1,
            'transfer_fee_percent' => 1.5,
        ]);
        $providerB = Provider::factory()->create([
            'points_to_value_ratio' => 1.0,
            'transfer_fee_percent' => 3.5,
        ]);

        config(['services.loyalty.app_transfer_fee_percent' => 5.0]);

        $this->pointService->awardPoints($user, $providerA, 1000, 'Initial balance');

        $preview = $this->exchangeService->preview($user, $providerA, $providerB, 1000);

        // Check structure
        expect($preview)->toHaveKeys([
            'points_to_send',
            'from_provider',
            'to_provider',
            'current_balance',
            'sufficient_balance',
            'gross_value',
            'fees',
            'net_value',
            'points_to_receive',
        ]);

        // Check values
        expect($preview['points_to_send'])->toBe(1000);
        expect($preview['gross_value'])->toBe(100.0);
        expect($preview['fees']['source_provider_fee']['percent'])->toBe(1.5);
        expect($preview['fees']['destination_provider_fee']['percent'])->toBe(3.5);
        expect($preview['fees']['app_fee']['percent'])->toBe(5.0);
        expect($preview['fees']['total']['percent'])->toBe(10.0);
        expect($preview['fees']['total']['value'])->toBe(10.0);
        expect($preview['net_value'])->toBe(90.0);
        expect($preview['points_to_receive'])->toBe(90);
        expect($preview['sufficient_balance'])->toBeTrue();

        // Balance should not have changed
        expect($user->getBalanceForProvider($providerA))->toBe(1000);
        expect($user->getBalanceForProvider($providerB))->toBe(0);
    });

    it('includes provider details in preview', function (): void {
        $user = User::factory()->create();
        $providerA = Provider::factory()->named('Source Provider')->create([
            'points_to_value_ratio' => 0.1,
            'transfer_fee_percent' => 2.0,
        ]);
        $providerB = Provider::factory()->named('Dest Provider')->create([
            'points_to_value_ratio' => 1.0,
            'transfer_fee_percent' => 3.0,
        ]);

        $this->pointService->awardPoints($user, $providerA, 1000, 'Initial balance');

        $preview = $this->exchangeService->preview($user, $providerA, $providerB, 500);

        expect($preview['from_provider']['slug'])->toBe('source-provider');
        expect($preview['from_provider']['name'])->toBe('Source Provider');
        expect($preview['from_provider']['points_to_value_ratio'])->toBe(0.1);
        expect($preview['from_provider']['transfer_fee_percent'])->toBe(2.0);

        expect($preview['to_provider']['slug'])->toBe('dest-provider');
        expect($preview['to_provider']['name'])->toBe('Dest Provider');
        expect($preview['to_provider']['points_to_value_ratio'])->toBe(1.0);
        expect($preview['to_provider']['transfer_fee_percent'])->toBe(3.0);
    });

    it('indicates insufficient balance in preview', function (): void {
        $user = User::factory()->create();
        $providerA = Provider::factory()->create();
        $providerB = Provider::factory()->create();

        $this->pointService->awardPoints($user, $providerA, 100, 'Initial balance');

        $preview = $this->exchangeService->preview($user, $providerA, $providerB, 500);

        expect($preview['sufficient_balance'])->toBeFalse();
        expect($preview['current_balance'])->toBe(100);
    });

    it('calculates individual fee values correctly', function (): void {
        $user = User::factory()->create();
        $providerA = Provider::factory()->create([
            'points_to_value_ratio' => 1.0, // 1 point = $1
            'transfer_fee_percent' => 2.0,
        ]);
        $providerB = Provider::factory()->create([
            'points_to_value_ratio' => 1.0,
            'transfer_fee_percent' => 3.0,
        ]);

        config(['services.loyalty.app_transfer_fee_percent' => 5.0]);

        $this->pointService->awardPoints($user, $providerA, 1000, 'Initial balance');

        $preview = $this->exchangeService->preview($user, $providerA, $providerB, 1000);

        // Gross value: 1000 × 1.0 = $1000
        expect($preview['gross_value'])->toBe(1000.0);
        expect($preview['fees']['source_provider_fee']['value'])->toBe(20.0); // 2% of 1000
        expect($preview['fees']['destination_provider_fee']['value'])->toBe(30.0); // 3% of 1000
        expect($preview['fees']['app_fee']['value'])->toBe(50.0); // 5% of 1000
        expect($preview['fees']['total']['value'])->toBe(100.0); // 10% of 1000
        expect($preview['net_value'])->toBe(900.0);
        expect($preview['points_to_receive'])->toBe(900);
    });
});

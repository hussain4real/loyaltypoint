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
});

describe('exchange', function (): void {
    it('exchanges points between providers with same rate', function (): void {
        $user = User::factory()->create();
        $providerA = Provider::factory()->create(['exchange_rate_base' => 1.0, 'exchange_fee_percent' => 0]);
        $providerB = Provider::factory()->create(['exchange_rate_base' => 1.0, 'exchange_fee_percent' => 0]);

        $this->pointService->awardPoints($user, $providerA, 1000, 'Initial balance');

        $result = $this->exchangeService->exchange($user, $providerA, $providerB, 500);

        expect($result['points_sent'])->toBe(500);
        expect($result['fee_deducted'])->toBe(0);
        expect($result['points_received'])->toBe(500);
        expect($user->getBalanceForProvider($providerA))->toBe(500);
        expect($user->getBalanceForProvider($providerB))->toBe(500);
    });

    it('applies exchange rate conversion correctly', function (): void {
        $user = User::factory()->create();
        $providerA = Provider::factory()->create(['exchange_rate_base' => 2.0, 'exchange_fee_percent' => 0]);
        $providerB = Provider::factory()->create(['exchange_rate_base' => 1.0, 'exchange_fee_percent' => 0]);

        $this->pointService->awardPoints($user, $providerA, 1000, 'Initial balance');

        // 500 points at rate 2.0 = 1000 value units, converted to rate 1.0 = 1000 points
        $result = $this->exchangeService->exchange($user, $providerA, $providerB, 500);

        expect($result['points_received'])->toBe(1000);
        expect($user->getBalanceForProvider($providerA))->toBe(500);
        expect($user->getBalanceForProvider($providerB))->toBe(1000);
    });

    it('applies exchange fee correctly', function (): void {
        $user = User::factory()->create();
        $providerA = Provider::factory()->create(['exchange_rate_base' => 1.0, 'exchange_fee_percent' => 10.0]);
        $providerB = Provider::factory()->create(['exchange_rate_base' => 1.0, 'exchange_fee_percent' => 0]);

        $this->pointService->awardPoints($user, $providerA, 1000, 'Initial balance');

        $result = $this->exchangeService->exchange($user, $providerA, $providerB, 1000);

        expect($result['points_sent'])->toBe(1000);
        expect($result['fee_deducted'])->toBe(100); // 10% of 1000
        expect($result['points_received'])->toBe(900); // 1000 - 100 fee
        expect($user->getBalanceForProvider($providerA))->toBe(0);
        expect($user->getBalanceForProvider($providerB))->toBe(900);
    });

    it('applies both fee and rate conversion', function (): void {
        $user = User::factory()->create();
        $providerA = Provider::factory()->create(['exchange_rate_base' => 1.0, 'exchange_fee_percent' => 5.0]);
        $providerB = Provider::factory()->create(['exchange_rate_base' => 2.0, 'exchange_fee_percent' => 0]);

        $this->pointService->awardPoints($user, $providerA, 1000, 'Initial balance');

        // Send 1000, fee 5% = 50, net 950 at rate 1.0, converted to rate 2.0 = 475 points
        $result = $this->exchangeService->exchange($user, $providerA, $providerB, 1000);

        expect($result['points_sent'])->toBe(1000);
        expect($result['fee_deducted'])->toBe(50);
        expect($result['points_received'])->toBe(475);
    });

    it('creates transfer transactions with correct types', function (): void {
        $user = User::factory()->create();
        $providerA = Provider::factory()->create();
        $providerB = Provider::factory()->create();

        $this->pointService->awardPoints($user, $providerA, 1000, 'Initial balance');

        $result = $this->exchangeService->exchange($user, $providerA, $providerB, 500);

        expect($result['transfer_out']->type)->toBe(TransactionType::TransferOut);
        expect($result['transfer_out']->points)->toBe(-500);
        expect($result['transfer_out']->provider_id)->toBe($providerA->id);

        expect($result['transfer_in']->type)->toBe(TransactionType::TransferIn);
        expect($result['transfer_in']->points)->toBeGreaterThan(0);
        expect($result['transfer_in']->provider_id)->toBe($providerB->id);
    });

    it('stores exchange metadata in transactions', function (): void {
        $user = User::factory()->create();
        $providerA = Provider::factory()->create(['exchange_fee_percent' => 5.0]);
        $providerB = Provider::factory()->create();

        $this->pointService->awardPoints($user, $providerA, 1000, 'Initial balance');

        $result = $this->exchangeService->exchange($user, $providerA, $providerB, 500);

        expect($result['transfer_out']->metadata)->toHaveKeys([
            'to_provider_id',
            'to_provider_slug',
            'points_sent',
            'fee_deducted',
            'fee_percent',
            'points_after_fee',
            'exchange_rate_from',
            'exchange_rate_to',
            'points_received',
        ]);

        expect($result['transfer_in']->metadata)->toHaveKeys([
            'from_provider_id',
            'from_provider_slug',
            'original_points',
            'fee_deducted',
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
        $providerA = Provider::factory()->create(['exchange_rate_base' => 0.01, 'exchange_fee_percent' => 0]);
        $providerB = Provider::factory()->create(['exchange_rate_base' => 100.0, 'exchange_fee_percent' => 0]);

        $this->pointService->awardPoints($user, $providerA, 1000, 'Initial balance');

        // 10 points at rate 0.01 = 0.1 value, converted to rate 100 = 0.001 (floors to 0)
        $this->exchangeService->exchange($user, $providerA, $providerB, 10);
    })->throws(InvalidArgumentException::class, 'Exchange would result in zero points. Try a larger amount.');
});

describe('preview', function (): void {
    it('returns preview without executing exchange', function (): void {
        $user = User::factory()->create();
        $providerA = Provider::factory()->create(['exchange_rate_base' => 1.0, 'exchange_fee_percent' => 10.0]);
        $providerB = Provider::factory()->create(['exchange_rate_base' => 1.0, 'exchange_fee_percent' => 0]);

        $this->pointService->awardPoints($user, $providerA, 1000, 'Initial balance');

        $preview = $this->exchangeService->preview($user, $providerA, $providerB, 500);

        expect($preview)->toHaveKeys([
            'points_to_send',
            'current_balance',
            'sufficient_balance',
            'fee_amount',
            'fee_percent',
            'points_after_fee',
            'points_to_receive',
            'exchange_rate_from',
            'exchange_rate_to',
        ]);

        expect($preview['points_to_send'])->toBe(500);
        expect($preview['fee_amount'])->toBe(50);
        expect($preview['points_to_receive'])->toBe(450);
        expect($preview['sufficient_balance'])->toBeTrue();

        // Balance should not have changed
        expect($user->getBalanceForProvider($providerA))->toBe(1000);
        expect($user->getBalanceForProvider($providerB))->toBe(0);
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
});

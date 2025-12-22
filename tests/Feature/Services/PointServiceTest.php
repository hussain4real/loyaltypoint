<?php

declare(strict_types=1);

use App\Enums\TransactionType;
use App\Models\PointTransaction;
use App\Models\Provider;
use App\Models\User;
use App\Services\PointService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->pointService = app(PointService::class);
    $this->provider = Provider::factory()->create();
});

describe('awardPoints', function (): void {
    it('creates a transaction when awarding points', function (): void {
        $user = User::factory()->create();

        $transaction = $this->pointService->awardPoints(
            user: $user,
            provider: $this->provider,
            points: 100,
            description: 'Purchase #ORD-123',
        );

        expect($transaction)
            ->toBeInstanceOf(PointTransaction::class)
            ->type->toBe(TransactionType::Earn)
            ->points->toBe(100)
            ->balance_after->toBe(100)
            ->description->toBe('Purchase #ORD-123')
            ->provider_id->toBe($this->provider->id);

        expect($user->getBalanceForProvider($this->provider))->toBe(100);
    });

    it('accumulates balance across multiple transactions', function (): void {
        $user = User::factory()->create();

        $this->pointService->awardPoints($user, $this->provider, 100, 'First purchase');
        $this->pointService->awardPoints($user, $this->provider, 200, 'Second purchase');
        $transaction = $this->pointService->awardPoints($user, $this->provider, 150, 'Third purchase');

        expect($transaction->balance_after)->toBe(450);
        expect($user->getBalanceForProvider($this->provider))->toBe(450);
    });

    it('maintains separate balances per provider', function (): void {
        $user = User::factory()->create();
        $provider2 = Provider::factory()->create();

        $this->pointService->awardPoints($user, $this->provider, 100, 'Provider 1 purchase');
        $this->pointService->awardPoints($user, $provider2, 200, 'Provider 2 purchase');

        expect($user->getBalanceForProvider($this->provider))->toBe(100);
        expect($user->getBalanceForProvider($provider2))->toBe(200);
    });

    it('rejects negative points', function (): void {
        $user = User::factory()->create();

        $this->pointService->awardPoints($user, $this->provider, -100, 'Invalid');
    })->throws(InvalidArgumentException::class, 'Points must be a positive integer.');

    it('rejects zero points', function (): void {
        $user = User::factory()->create();

        $this->pointService->awardPoints($user, $this->provider, 0, 'Invalid');
    })->throws(InvalidArgumentException::class, 'Points must be a positive integer.');

    it('stores metadata when provided', function (): void {
        $user = User::factory()->create();

        $transaction = $this->pointService->awardPoints(
            user: $user,
            provider: $this->provider,
            points: 100,
            description: 'Purchase with metadata',
            metadata: ['order_id' => 'ORD-456', 'source' => 'web'],
        );

        expect($transaction->metadata)->toBe(['order_id' => 'ORD-456', 'source' => 'web']);
    });

    it('allows specifying transaction type', function (): void {
        $user = User::factory()->create();

        $transaction = $this->pointService->awardPoints(
            user: $user,
            provider: $this->provider,
            points: 50,
            description: 'Bonus award',
            type: TransactionType::Bonus,
        );

        expect($transaction->type)->toBe(TransactionType::Bonus);
    });
});

describe('deductPoints', function (): void {
    it('deducts points from user balance', function (): void {
        $user = User::factory()->create();
        $this->pointService->awardPoints($user, $this->provider, 500, 'Initial balance');

        $transaction = $this->pointService->deductPoints(
            user: $user,
            provider: $this->provider,
            points: 200,
            description: 'Redemption',
        );

        expect($transaction)
            ->toBeInstanceOf(PointTransaction::class)
            ->type->toBe(TransactionType::Redeem)
            ->points->toBe(-200)
            ->balance_after->toBe(300);

        expect($user->getBalanceForProvider($this->provider))->toBe(300);
    });

    it('rejects deduction exceeding balance', function (): void {
        $user = User::factory()->create();
        $this->pointService->awardPoints($user, $this->provider, 100, 'Initial balance');

        $this->pointService->deductPoints($user, $this->provider, 200, 'Too much');
    })->throws(InvalidArgumentException::class, 'Insufficient points balance.');

    it('rejects negative points for deduction', function (): void {
        $user = User::factory()->create();
        $this->pointService->awardPoints($user, $this->provider, 100, 'Initial balance');

        $this->pointService->deductPoints($user, $this->provider, -50, 'Invalid');
    })->throws(InvalidArgumentException::class, 'Points must be a positive integer.');

    it('allows deduction of exact balance', function (): void {
        $user = User::factory()->create();
        $this->pointService->awardPoints($user, $this->provider, 100, 'Initial balance');

        $transaction = $this->pointService->deductPoints($user, $this->provider, 100, 'Full redemption');

        expect($transaction->balance_after)->toBe(0);
        expect($user->getBalanceForProvider($this->provider))->toBe(0);
    });

    it('only deducts from specified provider balance', function (): void {
        $user = User::factory()->create();
        $provider2 = Provider::factory()->create();

        $this->pointService->awardPoints($user, $this->provider, 100, 'Provider 1');
        $this->pointService->awardPoints($user, $provider2, 200, 'Provider 2');
        $this->pointService->deductPoints($user, $this->provider, 50, 'Deduct from provider 1');

        expect($user->getBalanceForProvider($this->provider))->toBe(50);
        expect($user->getBalanceForProvider($provider2))->toBe(200);
    });
});

describe('awardBonusPoints', function (): void {
    it('creates a bonus transaction', function (): void {
        $user = User::factory()->create();

        $transaction = $this->pointService->awardBonusPoints(
            user: $user,
            provider: $this->provider,
            points: 50,
            description: 'Welcome bonus',
        );

        expect($transaction)
            ->type->toBe(TransactionType::Bonus)
            ->points->toBe(50);
    });
});

describe('adjustPoints', function (): void {
    it('makes positive adjustments', function (): void {
        $user = User::factory()->create();

        $transaction = $this->pointService->adjustPoints(
            user: $user,
            provider: $this->provider,
            points: 100,
            description: 'Positive adjustment',
        );

        expect($transaction)
            ->type->toBe(TransactionType::Adjustment)
            ->points->toBe(100);
    });

    it('makes negative adjustments', function (): void {
        $user = User::factory()->create();
        $this->pointService->awardPoints($user, $this->provider, 200, 'Initial balance');

        $transaction = $this->pointService->adjustPoints(
            user: $user,
            provider: $this->provider,
            points: -100,
            description: 'Negative adjustment',
        );

        expect($transaction)
            ->type->toBe(TransactionType::Adjustment)
            ->points->toBe(-100)
            ->balance_after->toBe(100);
    });

    it('rejects zero adjustment', function (): void {
        $user = User::factory()->create();

        $this->pointService->adjustPoints($user, $this->provider, 0, 'Zero adjustment');
    })->throws(InvalidArgumentException::class, 'Adjustment points cannot be zero.');
});

describe('getBalance', function (): void {
    it('returns correct balance for provider', function (): void {
        $user = User::factory()->create();
        $this->pointService->awardPoints($user, $this->provider, 500, 'Test');
        $this->pointService->deductPoints($user, $this->provider, 200, 'Test');

        expect($this->pointService->getBalance($user, $this->provider))->toBe(300);
    });

    it('returns zero for provider with no transactions', function (): void {
        $user = User::factory()->create();

        expect($this->pointService->getBalance($user, $this->provider))->toBe(0);
    });
});

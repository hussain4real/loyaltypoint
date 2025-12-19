<?php

declare(strict_types=1);

use App\Enums\TransactionType;
use App\Models\PointTransaction;
use App\Models\User;
use App\Services\PointService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->pointService = app(PointService::class);
});

describe('awardPoints', function (): void {
    it('creates a transaction when awarding points', function (): void {
        $user = User::factory()->create();

        $transaction = $this->pointService->awardPoints(
            user: $user,
            points: 100,
            description: 'Purchase #ORD-123',
        );

        expect($transaction)
            ->toBeInstanceOf(PointTransaction::class)
            ->type->toBe(TransactionType::Earn)
            ->points->toBe(100)
            ->balance_after->toBe(100)
            ->description->toBe('Purchase #ORD-123');

        expect($user->fresh()->point_balance)->toBe(100);
    });

    it('accumulates balance across multiple transactions', function (): void {
        $user = User::factory()->create();

        $this->pointService->awardPoints($user, 100, 'First purchase');
        $this->pointService->awardPoints($user, 200, 'Second purchase');
        $transaction = $this->pointService->awardPoints($user, 150, 'Third purchase');

        expect($transaction->balance_after)->toBe(450);
        expect($user->fresh()->point_balance)->toBe(450);
    });

    it('rejects negative points', function (): void {
        $user = User::factory()->create();

        $this->pointService->awardPoints($user, -100, 'Invalid');
    })->throws(InvalidArgumentException::class, 'Points must be a positive integer.');

    it('rejects zero points', function (): void {
        $user = User::factory()->create();

        $this->pointService->awardPoints($user, 0, 'Invalid');
    })->throws(InvalidArgumentException::class, 'Points must be a positive integer.');

    it('stores metadata when provided', function (): void {
        $user = User::factory()->create();

        $transaction = $this->pointService->awardPoints(
            user: $user,
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
        $this->pointService->awardPoints($user, 500, 'Initial balance');

        $transaction = $this->pointService->deductPoints(
            user: $user,
            points: 200,
            description: 'Redemption',
        );

        expect($transaction)
            ->toBeInstanceOf(PointTransaction::class)
            ->type->toBe(TransactionType::Redeem)
            ->points->toBe(-200)
            ->balance_after->toBe(300);

        expect($user->fresh()->point_balance)->toBe(300);
    });

    it('rejects deduction exceeding balance', function (): void {
        $user = User::factory()->create();
        $this->pointService->awardPoints($user, 100, 'Initial balance');

        $this->pointService->deductPoints($user, 200, 'Too much');
    })->throws(InvalidArgumentException::class, 'Insufficient points balance.');

    it('rejects negative points for deduction', function (): void {
        $user = User::factory()->create();
        $this->pointService->awardPoints($user, 100, 'Initial balance');

        $this->pointService->deductPoints($user, -50, 'Invalid');
    })->throws(InvalidArgumentException::class, 'Points must be a positive integer.');

    it('allows deduction of exact balance', function (): void {
        $user = User::factory()->create();
        $this->pointService->awardPoints($user, 100, 'Initial balance');

        $transaction = $this->pointService->deductPoints($user, 100, 'Full redemption');

        expect($transaction->balance_after)->toBe(0);
        expect($user->fresh()->point_balance)->toBe(0);
    });
});

describe('awardBonusPoints', function (): void {
    it('creates a bonus transaction', function (): void {
        $user = User::factory()->create();

        $transaction = $this->pointService->awardBonusPoints(
            user: $user,
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
            points: 100,
            description: 'Positive adjustment',
        );

        expect($transaction)
            ->type->toBe(TransactionType::Adjustment)
            ->points->toBe(100);
    });

    it('makes negative adjustments', function (): void {
        $user = User::factory()->create();
        $this->pointService->awardPoints($user, 200, 'Initial balance');

        $transaction = $this->pointService->adjustPoints(
            user: $user,
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

        $this->pointService->adjustPoints($user, 0, 'Zero adjustment');
    })->throws(InvalidArgumentException::class, 'Adjustment points cannot be zero.');
});

describe('user model accessors', function (): void {
    it('calculates point_balance correctly', function (): void {
        $user = User::factory()->create();
        PointTransaction::factory()->for($user)->earn(500)->withBalance(500)->create();
        PointTransaction::factory()->for($user)->redeem(200)->withBalance(300)->create();

        expect($user->fresh()->point_balance)->toBe(300);
    });

    it('calculates loyalty_tier based on total earned', function (): void {
        $user = User::factory()->create();

        // Bronze (0-999)
        expect($user->loyalty_tier)->toBe('bronze');

        // Silver (1000-4999)
        PointTransaction::factory()->for($user)->earn(1500)->withBalance(1500)->create();
        expect($user->fresh()->loyalty_tier)->toBe('silver');

        // Gold (5000-9999)
        PointTransaction::factory()->for($user)->earn(4000)->withBalance(5500)->create();
        expect($user->fresh()->loyalty_tier)->toBe('gold');

        // Platinum (10000+)
        PointTransaction::factory()->for($user)->earn(5000)->withBalance(10500)->create();
        expect($user->fresh()->loyalty_tier)->toBe('platinum');
    });
});

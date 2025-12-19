<?php

declare(strict_types=1);

use App\Models\PointTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

describe('GET /api/v1/points/balance', function (): void {
    it('returns authenticated customer point balance', function (): void {
        $user = User::factory()->create();
        PointTransaction::factory()->for($user)->earn(500)->withBalance(500)->create();

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/points/balance');

        $response->assertOk()
            ->assertJsonPath('data.customer_id', $user->id)
            ->assertJsonPath('data.points_balance', 500)
            ->assertJsonPath('data.tier', 'bronze');
    });

    it('returns correct tier based on earned points', function (): void {
        $user = User::factory()->create();
        PointTransaction::factory()->for($user)->earn(5500)->withBalance(5500)->create();

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/points/balance');

        $response->assertOk()
            ->assertJsonPath('data.tier', 'gold');
    });

    it('returns zero balance for new customer', function (): void {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/points/balance');

        $response->assertOk()
            ->assertJsonPath('data.points_balance', 0)
            ->assertJsonPath('data.tier', 'bronze');
    });

    it('returns 401 for unauthenticated request', function (): void {
        $this->getJson('/api/v1/points/balance')
            ->assertUnauthorized();
    });

    it('includes last_transaction_at when transactions exist', function (): void {
        $user = User::factory()->create();
        PointTransaction::factory()
            ->for($user)
            ->earn(100)
            ->withBalance(100)
            ->create(['created_at' => now()->subDay()]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/points/balance');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'customer_id',
                    'points_balance',
                    'tier',
                    'last_transaction_at',
                ],
            ]);
    });
});

describe('GET /api/v1/points/transactions', function (): void {
    it('returns paginated transaction history', function (): void {
        $user = User::factory()->create();
        PointTransaction::factory()->for($user)->earn(100)->withBalance(100)->count(5)->create();

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/points/transactions');

        $response->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'type',
                        'points',
                        'balance_after',
                        'description',
                        'created_at',
                    ],
                ],
                'links',
                'meta',
            ]);
    });

    it('respects per_page parameter', function (): void {
        $user = User::factory()->create();
        PointTransaction::factory()->for($user)->earn(100)->withBalance(100)->count(10)->create();

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/points/transactions?per_page=3');

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.per_page', 3);
    });

    it('returns 401 for unauthenticated request', function (): void {
        $this->getJson('/api/v1/points/transactions')
            ->assertUnauthorized();
    });

    it('only returns transactions for authenticated user', function (): void {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        PointTransaction::factory()->for($user1)->earn(100)->withBalance(100)->create();
        PointTransaction::factory()->for($user2)->earn(200)->withBalance(200)->create();

        Sanctum::actingAs($user1);

        $response = $this->getJson('/api/v1/points/transactions');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.points', 100);
    });

    it('filters transactions by date range', function (): void {
        $user = User::factory()->create();
        PointTransaction::factory()->for($user)->earn(100)->withBalance(100)->create(['created_at' => now()->subDays(10)]);
        PointTransaction::factory()->for($user)->earn(200)->withBalance(300)->create(['created_at' => now()->subDays(5)]);
        PointTransaction::factory()->for($user)->earn(300)->withBalance(600)->create(['created_at' => now()]);

        Sanctum::actingAs($user);

        $from = now()->subDays(7)->format('Y-m-d');
        $to = now()->subDays(3)->format('Y-m-d');

        $response = $this->getJson("/api/v1/points/transactions?from={$from}&to={$to}");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.points', 200);
    });

    it('orders transactions by newest first', function (): void {
        $user = User::factory()->create();
        PointTransaction::factory()->for($user)->earn(100)->withBalance(100)->create(['created_at' => now()->subDays(2)]);
        PointTransaction::factory()->for($user)->earn(200)->withBalance(300)->create(['created_at' => now()->subDay()]);
        PointTransaction::factory()->for($user)->earn(300)->withBalance(600)->create(['created_at' => now()]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/points/transactions');

        $response->assertOk();
        $data = $response->json('data');
        expect($data[0]['points'])->toBe(300);
        expect($data[1]['points'])->toBe(200);
        expect($data[2]['points'])->toBe(100);
    });
});

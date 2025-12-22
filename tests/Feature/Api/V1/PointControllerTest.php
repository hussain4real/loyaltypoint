<?php

declare(strict_types=1);

use App\Models\PointTransaction;
use App\Models\Provider;
use App\Models\User;
use App\Models\UserProviderBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->provider = Provider::factory()->create(['slug' => 'test-provider']);
});

describe('GET /api/v1/points/balance', function (): void {
    it('returns authenticated customer point balance for all providers', function (): void {
        $user = User::factory()->create();
        UserProviderBalance::create([
            'user_id' => $user->id,
            'provider_id' => $this->provider->id,
            'balance' => 500,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/points/balance');

        $response->assertOk()
            ->assertJsonPath('data.customer_id', $user->id)
            ->assertJsonStructure([
                'data' => [
                    'customer_id',
                    'balances' => [
                        '*' => [
                            'provider' => ['id', 'name', 'slug'],
                            'points_balance',
                        ],
                    ],
                ],
            ]);
    });

    it('returns balance for specific provider when provider query param is set', function (): void {
        $user = User::factory()->create();
        UserProviderBalance::create([
            'user_id' => $user->id,
            'provider_id' => $this->provider->id,
            'balance' => 500,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/points/balance?provider=test-provider');

        $response->assertOk()
            ->assertJsonPath('data.customer_id', $user->id)
            ->assertJsonPath('data.points_balance', 500)
            ->assertJsonPath('data.provider.slug', 'test-provider');
    });

    it('returns zero balance for provider with no transactions', function (): void {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/points/balance?provider=test-provider');

        $response->assertOk()
            ->assertJsonPath('data.points_balance', 0);
    });

    it('returns 401 for unauthenticated request', function (): void {
        $this->getJson('/api/v1/points/balance')
            ->assertUnauthorized();
    });

    it('returns 404 for non-existent provider', function (): void {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/points/balance?provider=non-existent')
            ->assertNotFound();
    });
});

describe('GET /api/v1/points/transactions', function (): void {
    it('returns paginated transaction history with provider info', function (): void {
        $user = User::factory()->create();
        PointTransaction::factory()
            ->for($user)
            ->forProvider($this->provider)
            ->earn(100)
            ->withBalance(100)
            ->count(5)
            ->create();

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/points/transactions');

        $response->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'provider' => ['id', 'name', 'slug'],
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

    it('filters transactions by provider', function (): void {
        $user = User::factory()->create();
        $provider2 = Provider::factory()->create(['slug' => 'provider-2']);

        PointTransaction::factory()
            ->for($user)
            ->forProvider($this->provider)
            ->earn(100)
            ->withBalance(100)
            ->create();
        PointTransaction::factory()
            ->for($user)
            ->forProvider($provider2)
            ->earn(200)
            ->withBalance(200)
            ->create();

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/points/transactions?provider=test-provider');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.points', 100);
    });

    it('respects per_page parameter', function (): void {
        $user = User::factory()->create();
        PointTransaction::factory()
            ->for($user)
            ->forProvider($this->provider)
            ->earn(100)
            ->withBalance(100)
            ->count(10)
            ->create();

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
        PointTransaction::factory()
            ->for($user1)
            ->forProvider($this->provider)
            ->earn(100)
            ->withBalance(100)
            ->create();
        PointTransaction::factory()
            ->for($user2)
            ->forProvider($this->provider)
            ->earn(200)
            ->withBalance(200)
            ->create();

        Sanctum::actingAs($user1);

        $response = $this->getJson('/api/v1/points/transactions');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.points', 100);
    });

    it('filters transactions by date range', function (): void {
        $user = User::factory()->create();
        PointTransaction::factory()
            ->for($user)
            ->forProvider($this->provider)
            ->earn(100)
            ->withBalance(100)
            ->create(['created_at' => now()->subDays(10)]);
        PointTransaction::factory()
            ->for($user)
            ->forProvider($this->provider)
            ->earn(200)
            ->withBalance(300)
            ->create(['created_at' => now()->subDays(5)]);
        PointTransaction::factory()
            ->for($user)
            ->forProvider($this->provider)
            ->earn(300)
            ->withBalance(600)
            ->create(['created_at' => now()]);

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
        PointTransaction::factory()
            ->for($user)
            ->forProvider($this->provider)
            ->earn(100)
            ->withBalance(100)
            ->create(['created_at' => now()->subDays(2)]);
        PointTransaction::factory()
            ->for($user)
            ->forProvider($this->provider)
            ->earn(200)
            ->withBalance(300)
            ->create(['created_at' => now()->subDay()]);
        PointTransaction::factory()
            ->for($user)
            ->forProvider($this->provider)
            ->earn(300)
            ->withBalance(600)
            ->create(['created_at' => now()]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/points/transactions');

        $response->assertOk();
        $data = $response->json('data');
        expect($data[0]['points'])->toBe(300);
        expect($data[1]['points'])->toBe(200);
        expect($data[2]['points'])->toBe(100);
    });
});

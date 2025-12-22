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

describe('GET /api/v1/providers/{provider}/customers/{customer}/points', function (): void {
    it('returns customer point balance for valid token with points:read scope', function (): void {
        $customer = User::factory()->create();
        UserProviderBalance::create([
            'user_id' => $customer->id,
            'provider_id' => $this->provider->id,
            'balance' => 500,
        ]);

        $apiUser = User::factory()->create();
        Sanctum::actingAs($apiUser, ['points:read']);

        $response = $this->getJson("/api/v1/providers/{$this->provider->slug}/customers/{$customer->id}/points");

        $response->assertOk()
            ->assertJsonPath('data.customer_id', $customer->id)
            ->assertJsonPath('data.points_balance', 500)
            ->assertJsonPath('data.provider.slug', $this->provider->slug);
    });

    it('returns 401 for unauthenticated request', function (): void {
        $customer = User::factory()->create();

        $this->getJson("/api/v1/providers/{$this->provider->slug}/customers/{$customer->id}/points")
            ->assertUnauthorized();
    });

    it('returns 403 for token without points:read scope', function (): void {
        $customer = User::factory()->create();

        $apiUser = User::factory()->create();
        Sanctum::actingAs($apiUser, ['transactions:read']);

        $this->getJson("/api/v1/providers/{$this->provider->slug}/customers/{$customer->id}/points")
            ->assertForbidden();
    });

    it('returns 404 for non-existent customer', function (): void {
        $apiUser = User::factory()->create();
        Sanctum::actingAs($apiUser, ['points:read']);

        $this->getJson("/api/v1/providers/{$this->provider->slug}/customers/99999/points")
            ->assertNotFound();
    });

    it('returns 404 for non-existent provider', function (): void {
        $customer = User::factory()->create();

        $apiUser = User::factory()->create();
        Sanctum::actingAs($apiUser, ['points:read']);

        $this->getJson("/api/v1/providers/non-existent/customers/{$customer->id}/points")
            ->assertNotFound();
    });

    it('returns zero balance for customer with no transactions for provider', function (): void {
        $customer = User::factory()->create();

        $apiUser = User::factory()->create();
        Sanctum::actingAs($apiUser, ['points:read']);

        $response = $this->getJson("/api/v1/providers/{$this->provider->slug}/customers/{$customer->id}/points");

        $response->assertOk()
            ->assertJsonPath('data.points_balance', 0);
    });
});

describe('GET /api/v1/providers/{provider}/customers/{customer}/transactions', function (): void {
    it('returns paginated transaction history with transactions:read scope', function (): void {
        $customer = User::factory()->create();
        PointTransaction::factory()
            ->for($customer)
            ->forProvider($this->provider)
            ->earn(100)
            ->withBalance(100)
            ->count(5)
            ->create();

        $apiUser = User::factory()->create();
        Sanctum::actingAs($apiUser, ['transactions:read']);

        $response = $this->getJson("/api/v1/providers/{$this->provider->slug}/customers/{$customer->id}/transactions");

        $response->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'provider',
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

    it('returns 403 for token without transactions:read scope', function (): void {
        $customer = User::factory()->create();

        $apiUser = User::factory()->create();
        Sanctum::actingAs($apiUser, ['points:read']);

        $this->getJson("/api/v1/providers/{$this->provider->slug}/customers/{$customer->id}/transactions")
            ->assertForbidden();
    });

    it('returns 404 for non-existent customer', function (): void {
        $apiUser = User::factory()->create();
        Sanctum::actingAs($apiUser, ['transactions:read']);

        $this->getJson("/api/v1/providers/{$this->provider->slug}/customers/99999/transactions")
            ->assertNotFound();
    });

    it('only returns transactions for specified provider', function (): void {
        $customer = User::factory()->create();
        $provider2 = Provider::factory()->create(['slug' => 'provider-2']);

        PointTransaction::factory()
            ->for($customer)
            ->forProvider($this->provider)
            ->earn(100)
            ->withBalance(100)
            ->create();
        PointTransaction::factory()
            ->for($customer)
            ->forProvider($provider2)
            ->earn(200)
            ->withBalance(200)
            ->create();

        $apiUser = User::factory()->create();
        Sanctum::actingAs($apiUser, ['transactions:read']);

        $response = $this->getJson("/api/v1/providers/{$this->provider->slug}/customers/{$customer->id}/transactions");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.points', 100);
    });

    it('filters transactions by date range', function (): void {
        $customer = User::factory()->create();
        PointTransaction::factory()
            ->for($customer)
            ->forProvider($this->provider)
            ->earn(100)
            ->withBalance(100)
            ->create(['created_at' => now()->subDays(10)]);
        PointTransaction::factory()
            ->for($customer)
            ->forProvider($this->provider)
            ->earn(200)
            ->withBalance(300)
            ->create(['created_at' => now()->subDays(5)]);
        PointTransaction::factory()
            ->for($customer)
            ->forProvider($this->provider)
            ->earn(300)
            ->withBalance(600)
            ->create(['created_at' => now()]);

        $apiUser = User::factory()->create();
        Sanctum::actingAs($apiUser, ['transactions:read']);

        $from = now()->subDays(7)->format('Y-m-d');
        $to = now()->subDays(3)->format('Y-m-d');

        $response = $this->getJson("/api/v1/providers/{$this->provider->slug}/customers/{$customer->id}/transactions?from={$from}&to={$to}");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.points', 200);
    });
});

describe('POST /api/v1/providers/{provider}/customers/{customer}/points/award', function (): void {
    it('awards points with points:award scope', function (): void {
        $customer = User::factory()->create();

        $apiUser = User::factory()->create();
        Sanctum::actingAs($apiUser, ['points:award']);

        $response = $this->postJson("/api/v1/providers/{$this->provider->slug}/customers/{$customer->id}/points/award", [
            'points' => 100,
            'description' => 'Partner purchase bonus',
            'metadata' => ['order_id' => 'ORD-456'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.points', 100)
            ->assertJsonPath('data.balance_after', 100)
            ->assertJsonPath('message', 'Points awarded successfully.');

        expect($customer->getBalanceForProvider($this->provider))->toBe(100);
    });

    it('returns 403 for token without points:award scope', function (): void {
        $customer = User::factory()->create();

        $apiUser = User::factory()->create();
        Sanctum::actingAs($apiUser, ['points:read']);

        $this->postJson("/api/v1/providers/{$this->provider->slug}/customers/{$customer->id}/points/award", [
            'points' => 100,
            'description' => 'Test',
        ])->assertForbidden();
    });

    it('returns 422 for invalid input', function (): void {
        $customer = User::factory()->create();

        $apiUser = User::factory()->create();
        Sanctum::actingAs($apiUser, ['points:award']);

        $this->postJson("/api/v1/providers/{$this->provider->slug}/customers/{$customer->id}/points/award", [
            'points' => -100,
            'description' => '',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['points', 'description']);
    });

    it('returns 404 for non-existent customer', function (): void {
        $apiUser = User::factory()->create();
        Sanctum::actingAs($apiUser, ['points:award']);

        $this->postJson("/api/v1/providers/{$this->provider->slug}/customers/99999/points/award", [
            'points' => 100,
            'description' => 'Test',
        ])->assertNotFound();
    });

    it('returns 404 for non-existent provider', function (): void {
        $customer = User::factory()->create();

        $apiUser = User::factory()->create();
        Sanctum::actingAs($apiUser, ['points:award']);

        $this->postJson("/api/v1/providers/non-existent/customers/{$customer->id}/points/award", [
            'points' => 100,
            'description' => 'Test',
        ])->assertNotFound();
    });

    it('validates points maximum', function (): void {
        $customer = User::factory()->create();

        $apiUser = User::factory()->create();
        Sanctum::actingAs($apiUser, ['points:award']);

        $this->postJson("/api/v1/providers/{$this->provider->slug}/customers/{$customer->id}/points/award", [
            'points' => 1000001,
            'description' => 'Too many points',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['points']);
    });
});

describe('POST /api/v1/providers/{provider}/customers/{customer}/points/deduct', function (): void {
    it('deducts points with points:deduct scope', function (): void {
        $customer = User::factory()->create();
        UserProviderBalance::create([
            'user_id' => $customer->id,
            'provider_id' => $this->provider->id,
            'balance' => 500,
        ]);

        $apiUser = User::factory()->create();
        Sanctum::actingAs($apiUser, ['points:deduct']);

        $response = $this->postJson("/api/v1/providers/{$this->provider->slug}/customers/{$customer->id}/points/deduct", [
            'points' => 200,
            'description' => 'Reward redemption',
            'metadata' => ['reward_id' => 'RWD-789'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.points', -200)
            ->assertJsonPath('data.balance_after', 300)
            ->assertJsonPath('message', 'Points deducted successfully.');

        expect($customer->getBalanceForProvider($this->provider))->toBe(300);
    });

    it('returns 403 for token without points:deduct scope', function (): void {
        $customer = User::factory()->create();

        $apiUser = User::factory()->create();
        Sanctum::actingAs($apiUser, ['points:read']);

        $this->postJson("/api/v1/providers/{$this->provider->slug}/customers/{$customer->id}/points/deduct", [
            'points' => 100,
            'description' => 'Test',
        ])->assertForbidden();
    });

    it('returns 422 for insufficient balance', function (): void {
        $customer = User::factory()->create();
        UserProviderBalance::create([
            'user_id' => $customer->id,
            'provider_id' => $this->provider->id,
            'balance' => 100,
        ]);

        $apiUser = User::factory()->create();
        Sanctum::actingAs($apiUser, ['points:deduct']);

        $this->postJson("/api/v1/providers/{$this->provider->slug}/customers/{$customer->id}/points/deduct", [
            'points' => 200,
            'description' => 'Too many points',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['points']);
    });

    it('returns 404 for non-existent customer', function (): void {
        $apiUser = User::factory()->create();
        Sanctum::actingAs($apiUser, ['points:deduct']);

        $this->postJson("/api/v1/providers/{$this->provider->slug}/customers/99999/points/deduct", [
            'points' => 100,
            'description' => 'Test',
        ])->assertNotFound();
    });
});

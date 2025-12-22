<?php

declare(strict_types=1);

use App\Models\Provider;
use App\Models\User;
use App\Models\UserProviderBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

describe('GET /api/v1/providers', function (): void {
    it('returns list of active providers', function (): void {
        Provider::factory()->count(3)->create();
        Provider::factory()->inactive()->create();

        $response = $this->getJson('/api/v1/providers');

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'slug',
                        'is_active',
                        'exchange_rate_base',
                        'exchange_fee_percent',
                    ],
                ],
            ]);
    });

    it('returns empty list when no active providers', function (): void {
        Provider::factory()->inactive()->count(2)->create();

        $response = $this->getJson('/api/v1/providers');

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    });

    it('orders providers by name', function (): void {
        Provider::factory()->create(['name' => 'Zebra Corp']);
        Provider::factory()->create(['name' => 'Alpha Inc']);
        Provider::factory()->create(['name' => 'Beta LLC']);

        $response = $this->getJson('/api/v1/providers');

        $response->assertOk();
        $data = $response->json('data');
        expect($data[0]['name'])->toBe('Alpha Inc');
        expect($data[1]['name'])->toBe('Beta LLC');
        expect($data[2]['name'])->toBe('Zebra Corp');
    });
});

describe('GET /api/v1/providers/{provider}', function (): void {
    it('returns specific provider by slug', function (): void {
        $provider = Provider::factory()->create(['slug' => 'test-provider']);

        $response = $this->getJson('/api/v1/providers/test-provider');

        $response->assertOk()
            ->assertJsonPath('data.slug', 'test-provider')
            ->assertJsonPath('data.id', $provider->id);
    });

    it('returns 404 for non-existent provider', function (): void {
        $this->getJson('/api/v1/providers/non-existent')
            ->assertNotFound();
    });
});

describe('POST /api/v1/points/exchange/preview', function (): void {
    it('returns exchange preview for authenticated user', function (): void {
        $user = User::factory()->create();
        $providerA = Provider::factory()->create(['slug' => 'provider-a', 'exchange_fee_percent' => 5.0]);
        $providerB = Provider::factory()->create(['slug' => 'provider-b']);

        UserProviderBalance::create([
            'user_id' => $user->id,
            'provider_id' => $providerA->id,
            'balance' => 1000,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/points/exchange/preview', [
            'from_provider' => 'provider-a',
            'to_provider' => 'provider-b',
            'points' => 500,
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'points_to_send',
                    'current_balance',
                    'sufficient_balance',
                    'fee_amount',
                    'fee_percent',
                    'points_after_fee',
                    'points_to_receive',
                ],
            ])
            ->assertJsonPath('data.points_to_send', 500)
            ->assertJsonPath('data.fee_amount', 25)
            ->assertJsonPath('data.sufficient_balance', true);
    });

    it('returns 401 for unauthenticated request', function (): void {
        $this->postJson('/api/v1/points/exchange/preview', [
            'from_provider' => 'provider-a',
            'to_provider' => 'provider-b',
            'points' => 500,
        ])->assertUnauthorized();
    });

    it('validates required fields', function (): void {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/points/exchange/preview', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['from_provider', 'to_provider', 'points']);
    });

    it('validates providers exist', function (): void {
        $user = User::factory()->create();
        Provider::factory()->create(['slug' => 'provider-a']);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/points/exchange/preview', [
            'from_provider' => 'provider-a',
            'to_provider' => 'non-existent',
            'points' => 500,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['to_provider']);
    });
});

describe('POST /api/v1/points/exchange', function (): void {
    it('executes exchange for authenticated user', function (): void {
        $user = User::factory()->create();
        $providerA = Provider::factory()->create(['slug' => 'provider-a', 'exchange_fee_percent' => 0]);
        $providerB = Provider::factory()->create(['slug' => 'provider-b']);

        UserProviderBalance::create([
            'user_id' => $user->id,
            'provider_id' => $providerA->id,
            'balance' => 1000,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/points/exchange', [
            'from_provider' => 'provider-a',
            'to_provider' => 'provider-b',
            'points' => 500,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.points_sent', 500)
            ->assertJsonPath('data.fee_deducted', 0)
            ->assertJsonPath('data.points_received', 500)
            ->assertJsonPath('message', 'Points exchanged successfully.')
            ->assertJsonStructure([
                'data' => [
                    'points_sent',
                    'fee_deducted',
                    'points_received',
                    'transfer_out',
                    'transfer_in',
                ],
            ]);

        expect($user->getBalanceForProvider($providerA))->toBe(500);
        expect($user->getBalanceForProvider($providerB))->toBe(500);
    });

    it('applies exchange fee', function (): void {
        $user = User::factory()->create();
        $providerA = Provider::factory()->create(['slug' => 'provider-a', 'exchange_fee_percent' => 10.0]);
        $providerB = Provider::factory()->create(['slug' => 'provider-b']);

        UserProviderBalance::create([
            'user_id' => $user->id,
            'provider_id' => $providerA->id,
            'balance' => 1000,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/points/exchange', [
            'from_provider' => 'provider-a',
            'to_provider' => 'provider-b',
            'points' => 1000,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.points_sent', 1000)
            ->assertJsonPath('data.fee_deducted', 100)
            ->assertJsonPath('data.points_received', 900);
    });

    it('returns 401 for unauthenticated request', function (): void {
        $this->postJson('/api/v1/points/exchange', [
            'from_provider' => 'provider-a',
            'to_provider' => 'provider-b',
            'points' => 500,
        ])->assertUnauthorized();
    });

    it('returns 422 for insufficient balance', function (): void {
        $user = User::factory()->create();
        $providerA = Provider::factory()->create(['slug' => 'provider-a']);
        Provider::factory()->create(['slug' => 'provider-b']);

        UserProviderBalance::create([
            'user_id' => $user->id,
            'provider_id' => $providerA->id,
            'balance' => 100,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/points/exchange', [
            'from_provider' => 'provider-a',
            'to_provider' => 'provider-b',
            'points' => 500,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['points']);
    });

    it('validates same provider exchange', function (): void {
        $user = User::factory()->create();
        Provider::factory()->create(['slug' => 'provider-a']);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/points/exchange', [
            'from_provider' => 'provider-a',
            'to_provider' => 'provider-a',
            'points' => 500,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['to_provider']);
    });

    it('validates positive points', function (): void {
        $user = User::factory()->create();
        Provider::factory()->create(['slug' => 'provider-a']);
        Provider::factory()->create(['slug' => 'provider-b']);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/points/exchange', [
            'from_provider' => 'provider-a',
            'to_provider' => 'provider-b',
            'points' => 0,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['points']);
    });
});

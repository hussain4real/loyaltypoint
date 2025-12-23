<?php

declare(strict_types=1);

use App\Models\Provider;
use App\Models\User;
use App\Models\UserProviderBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Set a known app fee for testing
    config(['services.loyalty.app_transfer_fee_percent' => 5.0]);
});

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
                        'points_to_value_ratio',
                        'transfer_fee_percent',
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
    it('returns detailed exchange preview for authenticated user', function (): void {
        $user = User::factory()->create();
        $providerA = Provider::factory()->create([
            'slug' => 'provider-a',
            'points_to_value_ratio' => 0.1, // 10 points = $1
            'transfer_fee_percent' => 1.5,
        ]);
        $providerB = Provider::factory()->create([
            'slug' => 'provider-b',
            'points_to_value_ratio' => 1.0, // 1 point = $1
            'transfer_fee_percent' => 3.5,
        ]);

        UserProviderBalance::create([
            'user_id' => $user->id,
            'provider_id' => $providerA->id,
            'balance' => 1000,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/points/exchange/preview', [
            'from_provider' => 'provider-a',
            'to_provider' => 'provider-b',
            'points' => 1000,
        ]);

        // 1000 points × 0.1 = $100 gross
        // Fees: 1.5% + 3.5% + 5% = 10% → $10
        // Net: $90 → 90 points
        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'points_to_send',
                    'from_provider' => ['slug', 'name', 'points_to_value_ratio', 'transfer_fee_percent'],
                    'to_provider' => ['slug', 'name', 'points_to_value_ratio', 'transfer_fee_percent'],
                    'current_balance',
                    'sufficient_balance',
                    'gross_value',
                    'fees' => [
                        'source_provider_fee' => ['percent', 'value'],
                        'destination_provider_fee' => ['percent', 'value'],
                        'app_fee' => ['percent', 'value'],
                        'total' => ['percent', 'value'],
                    ],
                    'net_value',
                    'points_to_receive',
                ],
            ])
            ->assertJsonPath('data.points_to_send', 1000)
            ->assertJsonPath('data.sufficient_balance', true);

        // Check calculated values
        $data = $response->json('data');
        expect((float) $data['gross_value'])->toBe(100.0);
        expect((float) $data['fees']['total']['percent'])->toBe(10.0);
        expect((float) $data['fees']['total']['value'])->toBe(10.0);
        expect((float) $data['net_value'])->toBe(90.0);
        expect($data['points_to_receive'])->toBe(90);
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
    it('executes exchange with value-based conversion', function (): void {
        $user = User::factory()->create();
        $providerA = Provider::factory()->create([
            'slug' => 'provider-a',
            'points_to_value_ratio' => 0.1,
            'transfer_fee_percent' => 0,
        ]);
        $providerB = Provider::factory()->create([
            'slug' => 'provider-b',
            'points_to_value_ratio' => 1.0,
            'transfer_fee_percent' => 0,
        ]);

        config(['services.loyalty.app_transfer_fee_percent' => 0]);

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

        // 1000 points × 0.1 = $100 → $100 / 1.0 = 100 points
        $response->assertCreated()
            ->assertJsonPath('data.points_sent', 1000)
            ->assertJsonPath('data.points_received', 100)
            ->assertJsonPath('message', 'Points exchanged successfully.')
            ->assertJsonStructure([
                'data' => [
                    'points_sent',
                    'gross_value',
                    'total_fee_percent',
                    'total_fee_value',
                    'net_value',
                    'points_received',
                    'transfer_out',
                    'transfer_in',
                ],
            ]);

        $data = $response->json('data');
        expect((float) $data['gross_value'])->toBe(100.0);

        expect($user->getBalanceForProvider($providerA))->toBe(0);
        expect($user->getBalanceForProvider($providerB))->toBe(100);
    });

    it('applies all three fees correctly', function (): void {
        $user = User::factory()->create();
        $providerA = Provider::factory()->create([
            'slug' => 'provider-a',
            'points_to_value_ratio' => 0.1,
            'transfer_fee_percent' => 1.5,
        ]);
        $providerB = Provider::factory()->create([
            'slug' => 'provider-b',
            'points_to_value_ratio' => 1.0,
            'transfer_fee_percent' => 3.5,
        ]);

        config(['services.loyalty.app_transfer_fee_percent' => 5.0]);

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

        // 1000 points × 0.1 = $100 gross
        // Total fee: 10% → $10
        // Net: $90 → 90 points
        $response->assertCreated()
            ->assertJsonPath('data.points_sent', 1000)
            ->assertJsonPath('data.points_received', 90);

        $data = $response->json('data');
        expect((float) $data['gross_value'])->toBe(100.0);
        expect((float) $data['total_fee_percent'])->toBe(10.0);
        expect((float) $data['total_fee_value'])->toBe(10.0);
        expect((float) $data['net_value'])->toBe(90.0);

        expect($user->getBalanceForProvider($providerB))->toBe(90);
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

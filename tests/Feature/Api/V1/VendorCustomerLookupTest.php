<?php

use App\Models\Provider;
use App\Models\User;
use App\Models\VendorUserLink;
use App\Services\PointService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Vendor Customer Lookup', function (): void {
    describe('GET /v1/vendor/customers/by-vendor-email', function (): void {
        it('returns all linked accounts for a vendor email', function (): void {
            $alice = User::factory()->create(['email' => 'alice@example.com']);
            $alicia = User::factory()->create(['email' => 'alicia@example.com']);
            $providerA = Provider::factory()->create(['name' => 'Loyalty Plus', 'slug' => 'loyalty-plus']);
            $providerB = Provider::factory()->create(['name' => 'Rewards Hub', 'slug' => 'rewards-hub']);

            // Award points
            $pointService = app(PointService::class);
            $pointService->awardPoints($alice, $providerA, 500, 'Test points');
            $pointService->awardPoints($alicia, $providerB, 300, 'Test points');

            // Link both to same vendor email
            VendorUserLink::create([
                'user_id' => $alice->id,
                'provider_id' => $providerA->id,
                'vendor_email' => 'ali@vendor.com',
            ]);

            VendorUserLink::create([
                'user_id' => $alicia->id,
                'provider_id' => $providerB->id,
                'vendor_email' => 'ali@vendor.com',
            ]);

            $response = $this->getJson('/api/v1/vendor/customers/by-vendor-email?vendor_email=ali@vendor.com');

            $response->assertOk()
                ->assertJsonCount(2, 'data')
                ->assertJsonStructure([
                    'data' => [
                        '*' => [
                            'provider' => ['slug', 'name'],
                            'user' => ['id', 'name', 'email'],
                            'points_balance',
                            'linked_at',
                        ],
                    ],
                ]);

            $data = $response->json('data');
            $slugs = collect($data)->pluck('provider.slug')->toArray();
            expect($slugs)->toContain('loyalty-plus')
                ->and($slugs)->toContain('rewards-hub');
        });

        it('returns single linked account for vendor email with provider filter', function (): void {
            $alice = User::factory()->create(['email' => 'alice@example.com']);
            $alicia = User::factory()->create(['email' => 'alicia@example.com']);
            $providerA = Provider::factory()->create(['slug' => 'loyalty-plus']);
            $providerB = Provider::factory()->create(['slug' => 'rewards-hub']);

            $pointService = app(PointService::class);
            $pointService->awardPoints($alice, $providerA, 500, 'Test points');
            $pointService->awardPoints($alicia, $providerB, 300, 'Test points');

            VendorUserLink::create([
                'user_id' => $alice->id,
                'provider_id' => $providerA->id,
                'vendor_email' => 'ali@vendor.com',
            ]);

            VendorUserLink::create([
                'user_id' => $alicia->id,
                'provider_id' => $providerB->id,
                'vendor_email' => 'ali@vendor.com',
            ]);

            $response = $this->getJson('/api/v1/vendor/customers/by-vendor-email?vendor_email=ali@vendor.com&provider=loyalty-plus');

            $response->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.provider.slug', 'loyalty-plus')
                ->assertJsonPath('data.0.user.email', 'alice@example.com')
                ->assertJsonPath('data.0.points_balance', 500);
        });

        it('returns empty array for unknown vendor email', function (): void {
            $response = $this->getJson('/api/v1/vendor/customers/by-vendor-email?vendor_email=unknown@vendor.com');

            $response->assertOk()
                ->assertJsonPath('data', []);
        });

        it('returns empty array for unknown provider filter', function (): void {
            $user = User::factory()->create();
            $provider = Provider::factory()->create(['slug' => 'loyalty-plus']);

            VendorUserLink::create([
                'user_id' => $user->id,
                'provider_id' => $provider->id,
                'vendor_email' => 'ali@vendor.com',
            ]);

            $response = $this->getJson('/api/v1/vendor/customers/by-vendor-email?vendor_email=ali@vendor.com&provider=unknown-provider');

            $response->assertOk()
                ->assertJsonPath('data', []);
        });

        it('requires vendor_email parameter', function (): void {
            $response = $this->getJson('/api/v1/vendor/customers/by-vendor-email');

            $response->assertUnprocessable()
                ->assertJsonValidationErrors(['vendor_email']);
        });

        it('validates vendor_email format', function (): void {
            $response = $this->getJson('/api/v1/vendor/customers/by-vendor-email?vendor_email=not-an-email');

            $response->assertUnprocessable()
                ->assertJsonValidationErrors(['vendor_email']);
        });

        it('excludes inactive providers from results', function (): void {
            $alice = User::factory()->create();
            $alicia = User::factory()->create();
            $activeProvider = Provider::factory()->create(['slug' => 'active-provider']);
            $inactiveProvider = Provider::factory()->inactive()->create(['slug' => 'inactive-provider']);

            VendorUserLink::create([
                'user_id' => $alice->id,
                'provider_id' => $activeProvider->id,
                'vendor_email' => 'ali@vendor.com',
            ]);

            VendorUserLink::create([
                'user_id' => $alicia->id,
                'provider_id' => $inactiveProvider->id,
                'vendor_email' => 'ali@vendor.com',
            ]);

            $response = $this->getJson('/api/v1/vendor/customers/by-vendor-email?vendor_email=ali@vendor.com');

            $response->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.provider.slug', 'active-provider');
        });

        it('returns total points balance across all linked accounts', function (): void {
            $alice = User::factory()->create();
            $alicia = User::factory()->create();
            $providerA = Provider::factory()->create();
            $providerB = Provider::factory()->create();

            $pointService = app(PointService::class);
            $pointService->awardPoints($alice, $providerA, 500, 'Test');
            $pointService->awardPoints($alicia, $providerB, 300, 'Test');

            VendorUserLink::create([
                'user_id' => $alice->id,
                'provider_id' => $providerA->id,
                'vendor_email' => 'ali@vendor.com',
            ]);

            VendorUserLink::create([
                'user_id' => $alicia->id,
                'provider_id' => $providerB->id,
                'vendor_email' => 'ali@vendor.com',
            ]);

            $response = $this->getJson('/api/v1/vendor/customers/by-vendor-email?vendor_email=ali@vendor.com');

            $response->assertOk()
                ->assertJsonPath('meta.total_linked_accounts', 2);

            // Check individual balances
            $data = $response->json('data');
            $balances = collect($data)->pluck('points_balance')->toArray();
            expect($balances)->toContain(500)
                ->and($balances)->toContain(300);
        });
    });
});

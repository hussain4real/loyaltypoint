<?php

use App\Models\Provider;
use App\Models\User;
use App\Models\VendorUserLink;
use App\Services\PointService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('VendorPointController', function (): void {
    describe('GET /v1/vendor/points/balance', function (): void {
        it('returns balances for all accounts linked to same vendor email', function (): void {
            $user1 = User::factory()->create(['name' => 'Alice Personal', 'email' => 'alice@personal.com']);
            $user2 = User::factory()->create(['name' => 'Alice Work', 'email' => 'alice@work.com']);
            $providerA = Provider::factory()->create(['slug' => 'provider-a', 'name' => 'Provider A']);
            $providerB = Provider::factory()->create(['slug' => 'provider-b', 'name' => 'Provider B']);
            $providerC = Provider::factory()->create(['slug' => 'provider-c', 'name' => 'Provider C']);
            $providerUnlinked = Provider::factory()->create(['slug' => 'unlinked', 'name' => 'Unlinked Provider']);

            // Link user1 to providers A and B, user2 to provider C - all same vendor email
            VendorUserLink::create([
                'user_id' => $user1->id,
                'provider_id' => $providerA->id,
                'vendor_email' => 'alice@vendor.com',
            ]);
            VendorUserLink::create([
                'user_id' => $user1->id,
                'provider_id' => $providerB->id,
                'vendor_email' => 'alice@vendor.com',
            ]);
            VendorUserLink::create([
                'user_id' => $user2->id,
                'provider_id' => $providerC->id,
                'vendor_email' => 'alice@vendor.com',
            ]);

            // Award points
            $pointService = app(PointService::class);
            $pointService->awardPoints($user1, $providerA, 500, 'Provider A points');
            $pointService->awardPoints($user1, $providerB, 1000, 'Provider B points');
            $pointService->awardPoints($user2, $providerC, 2000, 'Provider C points');
            $pointService->awardPoints($user1, $providerUnlinked, 9999, 'Unlinked points');

            // Authenticate as user1 - should see ALL 3 linked providers
            $response = $this->actingAs($user1)
                ->getJson('/api/v1/vendor/points/balance');

            $response->assertOk();

            $balances = $response->json('data');

            // Should have all 3 linked providers
            expect($balances)->toHaveCount(3);

            $slugs = collect($balances)->pluck('provider.slug')->toArray();
            expect($slugs)->toContain('provider-a');
            expect($slugs)->toContain('provider-b');
            expect($slugs)->toContain('provider-c');
            expect($slugs)->not->toContain('unlinked');

            // Verify balances and user info
            $balanceA = collect($balances)->firstWhere('provider.slug', 'provider-a');
            $balanceB = collect($balances)->firstWhere('provider.slug', 'provider-b');
            $balanceC = collect($balances)->firstWhere('provider.slug', 'provider-c');

            expect($balanceA['points_balance'])->toBe(500);
            expect($balanceA['user']['email'])->toBe('alice@personal.com');

            expect($balanceB['points_balance'])->toBe(1000);
            expect($balanceB['user']['email'])->toBe('alice@personal.com');

            expect($balanceC['points_balance'])->toBe(2000);
            expect($balanceC['user']['email'])->toBe('alice@work.com');
        });

        it('returns same data regardless of which linked user authenticates', function (): void {
            $user1 = User::factory()->create();
            $user2 = User::factory()->create();
            $providerA = Provider::factory()->create();
            $providerB = Provider::factory()->create();

            VendorUserLink::create([
                'user_id' => $user1->id,
                'provider_id' => $providerA->id,
                'vendor_email' => 'shared@vendor.com',
            ]);
            VendorUserLink::create([
                'user_id' => $user2->id,
                'provider_id' => $providerB->id,
                'vendor_email' => 'shared@vendor.com',
            ]);

            $pointService = app(PointService::class);
            $pointService->awardPoints($user1, $providerA, 100, 'Points');
            $pointService->awardPoints($user2, $providerB, 200, 'Points');

            // Both users should see the same 2 balances
            $response1 = $this->actingAs($user1)->getJson('/api/v1/vendor/points/balance');
            $response2 = $this->actingAs($user2)->getJson('/api/v1/vendor/points/balance');

            expect($response1->json('data'))->toHaveCount(2);
            expect($response2->json('data'))->toHaveCount(2);

            // Same total points visible
            $total1 = collect($response1->json('data'))->sum('points_balance');
            $total2 = collect($response2->json('data'))->sum('points_balance');
            expect($total1)->toBe(300);
            expect($total2)->toBe(300);
        });

        it('returns 400 when user has no vendor link', function (): void {
            $user = User::factory()->create();
            $provider = Provider::factory()->create();

            // Award points but no vendor link
            app(PointService::class)->awardPoints($user, $provider, 500, 'Test points');

            $response = $this->actingAs($user)
                ->getJson('/api/v1/vendor/points/balance');

            $response->assertStatus(400)
                ->assertJsonPath('message', 'No vendor account linked. Use the standard /points/balance endpoint with ?provider= parameter.');
        });

        it('requires authentication', function (): void {
            $response = $this->getJson('/api/v1/vendor/points/balance');

            $response->assertUnauthorized();
        });

        it('returns zero balance when no transactions exist', function (): void {
            $user = User::factory()->create();
            $provider = Provider::factory()->create();

            VendorUserLink::create([
                'user_id' => $user->id,
                'provider_id' => $provider->id,
                'vendor_email' => 'user@vendor.com',
            ]);

            $response = $this->actingAs($user)
                ->getJson('/api/v1/vendor/points/balance');

            $response->assertOk();

            $balances = $response->json('data');
            expect($balances)->toHaveCount(1);
            expect($balances[0]['points_balance'])->toBe(0);
        });
    });

    describe('GET /v1/vendor/points/transactions', function (): void {
        it('returns transactions for all accounts linked to same vendor email', function (): void {
            $user1 = User::factory()->create();
            $user2 = User::factory()->create();
            $providerA = Provider::factory()->create(['slug' => 'provider-a']);
            $providerB = Provider::factory()->create(['slug' => 'provider-b']);
            $providerUnlinked = Provider::factory()->create(['slug' => 'unlinked']);

            // Link both users to same vendor email
            VendorUserLink::create([
                'user_id' => $user1->id,
                'provider_id' => $providerA->id,
                'vendor_email' => 'user@vendor.com',
            ]);
            VendorUserLink::create([
                'user_id' => $user2->id,
                'provider_id' => $providerB->id,
                'vendor_email' => 'user@vendor.com',
            ]);

            // Create transactions
            $pointService = app(PointService::class);
            $pointService->awardPoints($user1, $providerA, 100, 'Provider A transaction');
            $pointService->awardPoints($user2, $providerB, 200, 'Provider B transaction');
            $pointService->awardPoints($user1, $providerUnlinked, 500, 'Unlinked transaction');

            $response = $this->actingAs($user1)
                ->getJson('/api/v1/vendor/points/transactions');

            $response->assertOk();

            $transactions = $response->json('data');

            // Should have transactions from both linked user+provider combinations
            expect($transactions)->toHaveCount(2);

            $slugs = collect($transactions)->pluck('provider.slug')->unique()->values()->toArray();
            expect($slugs)->toContain('provider-a');
            expect($slugs)->toContain('provider-b');
            expect($slugs)->not->toContain('unlinked');
        });

        it('returns 400 when user has no vendor link', function (): void {
            $user = User::factory()->create();

            $response = $this->actingAs($user)
                ->getJson('/api/v1/vendor/points/transactions');

            $response->assertStatus(400)
                ->assertJsonPath('message', 'No vendor account linked. Use the standard /points/transactions endpoint with ?provider= parameter.');
        });

        it('requires authentication', function (): void {
            $response = $this->getJson('/api/v1/vendor/points/transactions');

            $response->assertUnauthorized();
        });

        it('supports date filtering with to parameter', function (): void {
            $user = User::factory()->create();
            $provider = Provider::factory()->create();

            VendorUserLink::create([
                'user_id' => $user->id,
                'provider_id' => $provider->id,
                'vendor_email' => 'user@vendor.com',
            ]);

            $pointService = app(PointService::class);
            $pointService->awardPoints($user, $provider, 100, 'Transaction 1');
            $pointService->awardPoints($user, $provider, 200, 'Transaction 2');

            // Filter to yesterday - should get no transactions since all are today
            $response = $this->actingAs($user)
                ->getJson('/api/v1/vendor/points/transactions?to='.now()->subDay()->toDateString());

            $response->assertOk();

            $transactions = $response->json('data');
            expect($transactions)->toBeEmpty();
        });

        it('supports pagination', function (): void {
            $user = User::factory()->create();
            $provider = Provider::factory()->create();

            VendorUserLink::create([
                'user_id' => $user->id,
                'provider_id' => $provider->id,
                'vendor_email' => 'user@vendor.com',
            ]);

            $pointService = app(PointService::class);
            for ($i = 1; $i <= 20; $i++) {
                $pointService->awardPoints($user, $provider, 10, "Transaction {$i}");
            }

            $response = $this->actingAs($user)
                ->getJson('/api/v1/vendor/points/transactions?per_page=5');

            $response->assertOk()
                ->assertJsonPath('meta.per_page', 5)
                ->assertJsonPath('meta.total', 20);

            expect($response->json('data'))->toHaveCount(5);
        });

        it('returns empty list when no transactions exist', function (): void {
            $user = User::factory()->create();
            $provider = Provider::factory()->create();

            VendorUserLink::create([
                'user_id' => $user->id,
                'provider_id' => $provider->id,
                'vendor_email' => 'user@vendor.com',
            ]);

            $response = $this->actingAs($user)
                ->getJson('/api/v1/vendor/points/transactions');

            $response->assertOk();
            expect($response->json('data'))->toBeEmpty();
        });
    });
});

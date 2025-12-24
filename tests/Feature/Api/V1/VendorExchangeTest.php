<?php

use App\Models\Provider;
use App\Models\User;
use App\Models\VendorUserLink;
use App\Services\PointService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Vendor Exchange (Cross-Account)', function (): void {
    beforeEach(function (): void {
        // Set app fee to 5% for consistent tests
        config(['services.loyalty.app_transfer_fee_percent' => 5.0]);
    });

    describe('POST /v1/vendor/points/exchange/preview', function (): void {
        it('previews cross-account exchange for linked vendor email', function (): void {
            $alice = User::factory()->create(['email' => 'alice@example.com']);
            $alicia = User::factory()->create(['email' => 'alicia@example.com']);

            $providerA = Provider::factory()
                ->withPointsToValueRatio(0.1)  // 10 points = $1
                ->withTransferFee(1.5)
                ->create(['name' => 'Loyalty Plus', 'slug' => 'loyalty-plus']);

            $providerB = Provider::factory()
                ->withPointsToValueRatio(1.0)  // 1 point = $1
                ->withTransferFee(3.5)
                ->create(['name' => 'Rewards Hub', 'slug' => 'rewards-hub']);

            // Award points to alice at providerA
            $pointService = app(PointService::class);
            $pointService->awardPoints($alice, $providerA, 1000, 'Test points');

            // Link both accounts to same vendor email
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

            $response = $this->postJson('/api/v1/vendor/points/exchange/preview', [
                'vendor_email' => 'ali@vendor.com',
                'from_provider' => 'loyalty-plus',
                'to_provider' => 'rewards-hub',
                'points' => 100,
            ]);

            // 100 points × 0.1 = $10 gross
            // Fees: 1.5% + 3.5% + 5% = 10% = $1
            // Net: $10 - $1 = $9
            // Points received: $9 / 1.0 = 9 points

            $response->assertOk()
                ->assertJsonStructure([
                    'data' => [
                        'points_to_send',
                        'from_provider',
                        'to_provider',
                        'from_account',
                        'to_account',
                        'current_balance',
                        'sufficient_balance',
                        'gross_value',
                        'fees',
                        'net_value',
                        'points_to_receive',
                    ],
                ])
                ->assertJsonPath('data.points_to_send', 100)
                ->assertJsonPath('data.from_account.email', 'alice@example.com')
                ->assertJsonPath('data.to_account.email', 'alicia@example.com')
                ->assertJsonPath('data.points_to_receive', 9);

            // Check float values by extracting response data
            $data = $response->json('data');
            expect((float) $data['gross_value'])->toBe(10.0)
                ->and((float) $data['net_value'])->toBe(9.0);
        });

        it('fails if vendor email has no link for source provider', function (): void {
            $alice = User::factory()->create();
            $providerA = Provider::factory()->create(['slug' => 'loyalty-plus']);
            $providerB = Provider::factory()->create(['slug' => 'rewards-hub']);

            // Only link to providerB, not providerA
            VendorUserLink::create([
                'user_id' => $alice->id,
                'provider_id' => $providerB->id,
                'vendor_email' => 'ali@vendor.com',
            ]);

            $response = $this->postJson('/api/v1/vendor/points/exchange/preview', [
                'vendor_email' => 'ali@vendor.com',
                'from_provider' => 'loyalty-plus',
                'to_provider' => 'rewards-hub',
                'points' => 100,
            ]);

            $response->assertUnprocessable()
                ->assertJsonPath('message', 'No linked account found for the source provider.');
        });

        it('fails if vendor email has no link for destination provider', function (): void {
            $alice = User::factory()->create();
            $providerA = Provider::factory()->create(['slug' => 'loyalty-plus']);
            Provider::factory()->create(['slug' => 'rewards-hub']);

            // Only link to providerA, not providerB
            VendorUserLink::create([
                'user_id' => $alice->id,
                'provider_id' => $providerA->id,
                'vendor_email' => 'ali@vendor.com',
            ]);

            $response = $this->postJson('/api/v1/vendor/points/exchange/preview', [
                'vendor_email' => 'ali@vendor.com',
                'from_provider' => 'loyalty-plus',
                'to_provider' => 'rewards-hub',
                'points' => 100,
            ]);

            $response->assertUnprocessable()
                ->assertJsonPath('message', 'No linked account found for the destination provider.');
        });

        it('shows insufficient balance when source account has not enough points', function (): void {
            $alice = User::factory()->create();
            $alicia = User::factory()->create();

            $providerA = Provider::factory()->create(['slug' => 'loyalty-plus']);
            $providerB = Provider::factory()->create(['slug' => 'rewards-hub']);

            // Alice has only 50 points
            app(PointService::class)->awardPoints($alice, $providerA, 50, 'Test');

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

            $response = $this->postJson('/api/v1/vendor/points/exchange/preview', [
                'vendor_email' => 'ali@vendor.com',
                'from_provider' => 'loyalty-plus',
                'to_provider' => 'rewards-hub',
                'points' => 100,
            ]);

            $response->assertOk()
                ->assertJsonPath('data.current_balance', 50)
                ->assertJsonPath('data.sufficient_balance', false);
        });

        it('requires all fields', function (): void {
            $response = $this->postJson('/api/v1/vendor/points/exchange/preview', []);

            $response->assertUnprocessable()
                ->assertJsonValidationErrors(['vendor_email', 'from_provider', 'to_provider', 'points']);
        });
    });

    describe('POST /v1/vendor/points/exchange', function (): void {
        it('executes cross-account exchange', function (): void {
            $alice = User::factory()->create(['email' => 'alice@example.com']);
            $alicia = User::factory()->create(['email' => 'alicia@example.com']);

            $providerA = Provider::factory()
                ->withPointsToValueRatio(0.1)
                ->withTransferFee(1.5)
                ->create(['slug' => 'loyalty-plus']);

            $providerB = Provider::factory()
                ->withPointsToValueRatio(1.0)
                ->withTransferFee(3.5)
                ->create(['slug' => 'rewards-hub']);

            $pointService = app(PointService::class);
            $pointService->awardPoints($alice, $providerA, 1000, 'Initial');

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

            $response = $this->postJson('/api/v1/vendor/points/exchange', [
                'vendor_email' => 'ali@vendor.com',
                'from_provider' => 'loyalty-plus',
                'to_provider' => 'rewards-hub',
                'points' => 100,
            ]);

            $response->assertOk()
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
                ])
                ->assertJsonPath('data.points_sent', 100)
                ->assertJsonPath('data.points_received', 9);

            // Verify balances
            expect($alice->getBalanceForProvider($providerA))->toBe(900)
                ->and($alicia->getBalanceForProvider($providerB))->toBe(9);
        });

        it('creates transactions for both users', function (): void {
            $alice = User::factory()->create();
            $alicia = User::factory()->create();

            $providerA = Provider::factory()
                ->withPointsToValueRatio(0.1)
                ->withTransferFee(1.5)
                ->create(['slug' => 'loyalty-plus']);

            $providerB = Provider::factory()
                ->withPointsToValueRatio(1.0)
                ->withTransferFee(3.5)
                ->create(['slug' => 'rewards-hub']);

            app(PointService::class)->awardPoints($alice, $providerA, 1000, 'Initial');

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

            $this->postJson('/api/v1/vendor/points/exchange', [
                'vendor_email' => 'ali@vendor.com',
                'from_provider' => 'loyalty-plus',
                'to_provider' => 'rewards-hub',
                'points' => 100,
            ]);

            // Alice should have transfer_out transaction
            $aliceTransaction = $alice->pointTransactions()
                ->where('provider_id', $providerA->id)
                ->where('type', 'transfer_out')
                ->first();

            expect($aliceTransaction)->not->toBeNull()
                ->and($aliceTransaction->points)->toBe(-100);

            // Alicia should have transfer_in transaction
            $aliciaTransaction = $alicia->pointTransactions()
                ->where('provider_id', $providerB->id)
                ->where('type', 'transfer_in')
                ->first();

            expect($aliciaTransaction)->not->toBeNull()
                ->and($aliciaTransaction->points)->toBe(9);
        });

        it('links transactions with exchange_id', function (): void {
            $alice = User::factory()->create();
            $alicia = User::factory()->create();

            $providerA = Provider::factory()->create(['slug' => 'loyalty-plus']);
            $providerB = Provider::factory()->create(['slug' => 'rewards-hub']);

            app(PointService::class)->awardPoints($alice, $providerA, 1000, 'Initial');

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

            $response = $this->postJson('/api/v1/vendor/points/exchange', [
                'vendor_email' => 'ali@vendor.com',
                'from_provider' => 'loyalty-plus',
                'to_provider' => 'rewards-hub',
                'points' => 100,
            ]);

            $transferOutId = $response->json('data.transfer_out.id');
            $transferInId = $response->json('data.transfer_in.id');

            $transferOut = $alice->pointTransactions()->find($transferOutId);
            $transferIn = $alicia->pointTransactions()->find($transferInId);

            expect($transferOut->metadata['exchange_id'])->toBe($transferIn->metadata['exchange_id']);
        });

        it('fails if source account has insufficient balance', function (): void {
            $alice = User::factory()->create();
            $alicia = User::factory()->create();

            $providerA = Provider::factory()->create(['slug' => 'loyalty-plus']);
            $providerB = Provider::factory()->create(['slug' => 'rewards-hub']);

            // Alice has only 50 points
            app(PointService::class)->awardPoints($alice, $providerA, 50, 'Initial');

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

            $response = $this->postJson('/api/v1/vendor/points/exchange', [
                'vendor_email' => 'ali@vendor.com',
                'from_provider' => 'loyalty-plus',
                'to_provider' => 'rewards-hub',
                'points' => 100,
            ]);

            $response->assertUnprocessable()
                ->assertJsonPath('message', 'Insufficient points balance for exchange.');
        });

        it('fails if exchange results in zero points', function (): void {
            $alice = User::factory()->create();
            $alicia = User::factory()->create();

            $providerA = Provider::factory()
                ->withPointsToValueRatio(0.001)  // Very low value
                ->create(['slug' => 'loyalty-plus']);

            $providerB = Provider::factory()
                ->withPointsToValueRatio(100.0)  // Very high value
                ->create(['slug' => 'rewards-hub']);

            app(PointService::class)->awardPoints($alice, $providerA, 1000, 'Initial');

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

            $response = $this->postJson('/api/v1/vendor/points/exchange', [
                'vendor_email' => 'ali@vendor.com',
                'from_provider' => 'loyalty-plus',
                'to_provider' => 'rewards-hub',
                'points' => 10,
            ]);

            $response->assertUnprocessable()
                ->assertJsonPath('message', 'Exchange would result in zero points. Try exchanging more points.');
        });

        it('works when same user owns both provider accounts', function (): void {
            // Alice has both providers linked
            $alice = User::factory()->create(['email' => 'alice@example.com']);

            $providerA = Provider::factory()
                ->withPointsToValueRatio(0.1)
                ->withTransferFee(1.5)
                ->create(['slug' => 'loyalty-plus']);

            $providerB = Provider::factory()
                ->withPointsToValueRatio(1.0)
                ->withTransferFee(3.5)
                ->create(['slug' => 'rewards-hub']);

            app(PointService::class)->awardPoints($alice, $providerA, 1000, 'Initial');

            VendorUserLink::create([
                'user_id' => $alice->id,
                'provider_id' => $providerA->id,
                'vendor_email' => 'ali@vendor.com',
            ]);

            VendorUserLink::create([
                'user_id' => $alice->id,
                'provider_id' => $providerB->id,
                'vendor_email' => 'ali@vendor.com',
            ]);

            $response = $this->postJson('/api/v1/vendor/points/exchange', [
                'vendor_email' => 'ali@vendor.com',
                'from_provider' => 'loyalty-plus',
                'to_provider' => 'rewards-hub',
                'points' => 100,
            ]);

            $response->assertOk();

            expect($alice->getBalanceForProvider($providerA))->toBe(900)
                ->and($alice->getBalanceForProvider($providerB))->toBe(9);
        });

        it('fails with inactive source provider', function (): void {
            $alice = User::factory()->create();
            $alicia = User::factory()->create();

            $providerA = Provider::factory()->inactive()->create(['slug' => 'loyalty-plus']);
            $providerB = Provider::factory()->create(['slug' => 'rewards-hub']);

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

            $response = $this->postJson('/api/v1/vendor/points/exchange', [
                'vendor_email' => 'ali@vendor.com',
                'from_provider' => 'loyalty-plus',
                'to_provider' => 'rewards-hub',
                'points' => 100,
            ]);

            $response->assertUnprocessable();
        });

        it('fails with inactive destination provider', function (): void {
            $alice = User::factory()->create();
            $alicia = User::factory()->create();

            $providerA = Provider::factory()->create(['slug' => 'loyalty-plus']);
            $providerB = Provider::factory()->inactive()->create(['slug' => 'rewards-hub']);

            app(PointService::class)->awardPoints($alice, $providerA, 1000, 'Initial');

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

            $response = $this->postJson('/api/v1/vendor/points/exchange', [
                'vendor_email' => 'ali@vendor.com',
                'from_provider' => 'loyalty-plus',
                'to_provider' => 'rewards-hub',
                'points' => 100,
            ]);

            $response->assertUnprocessable();
        });

        it('fails if trying to exchange to same provider', function (): void {
            $alice = User::factory()->create();

            $provider = Provider::factory()->create(['slug' => 'loyalty-plus']);

            app(PointService::class)->awardPoints($alice, $provider, 1000, 'Initial');

            VendorUserLink::create([
                'user_id' => $alice->id,
                'provider_id' => $provider->id,
                'vendor_email' => 'ali@vendor.com',
            ]);

            $response = $this->postJson('/api/v1/vendor/points/exchange', [
                'vendor_email' => 'ali@vendor.com',
                'from_provider' => 'loyalty-plus',
                'to_provider' => 'loyalty-plus',
                'points' => 100,
            ]);

            $response->assertUnprocessable()
                ->assertJsonValidationErrors(['to_provider']);
        });
    });
});

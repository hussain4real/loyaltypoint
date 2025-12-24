<?php

use App\Models\Provider;
use App\Models\User;
use App\Models\VendorUserLink;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('VendorUserLink Model', function (): void {
    it('can create a vendor user link', function (): void {
        $user = User::factory()->create();
        $provider = Provider::factory()->create();

        $link = VendorUserLink::create([
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'vendor_email' => 'alice@vendor.com',
        ]);

        expect($link)->toBeInstanceOf(VendorUserLink::class)
            ->and($link->user_id)->toBe($user->id)
            ->and($link->provider_id)->toBe($provider->id)
            ->and($link->vendor_email)->toBe('alice@vendor.com')
            ->and($link->linked_at)->not->toBeNull();
    });

    it('belongs to a user', function (): void {
        $user = User::factory()->create();
        $provider = Provider::factory()->create();

        $link = VendorUserLink::create([
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'vendor_email' => 'alice@vendor.com',
        ]);

        expect($link->user)->toBeInstanceOf(User::class)
            ->and($link->user->id)->toBe($user->id);
    });

    it('belongs to a provider', function (): void {
        $user = User::factory()->create();
        $provider = Provider::factory()->create();

        $link = VendorUserLink::create([
            'user_id' => $user->id,
            'provider_id' => $provider->id,
            'vendor_email' => 'alice@vendor.com',
        ]);

        expect($link->provider)->toBeInstanceOf(Provider::class)
            ->and($link->provider->id)->toBe($provider->id);
    });

    it('allows same vendor email to link different providers to different users', function (): void {
        $alice = User::factory()->create(['email' => 'alice@example.com']);
        $alicia = User::factory()->create(['email' => 'alicia@example.com']);
        $providerA = Provider::factory()->create(['slug' => 'loyalty-plus']);
        $providerB = Provider::factory()->create(['slug' => 'rewards-hub']);

        // Same vendor email links to different users for different providers
        $link1 = VendorUserLink::create([
            'user_id' => $alice->id,
            'provider_id' => $providerA->id,
            'vendor_email' => 'ali@vendor.com',
        ]);

        $link2 = VendorUserLink::create([
            'user_id' => $alicia->id,
            'provider_id' => $providerB->id,
            'vendor_email' => 'ali@vendor.com',
        ]);

        expect($link1->exists)->toBeTrue()
            ->and($link2->exists)->toBeTrue()
            ->and($link1->user_id)->not->toBe($link2->user_id)
            ->and($link1->provider_id)->not->toBe($link2->provider_id);
    });

    it('prevents same vendor email from linking same provider twice', function (): void {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $provider = Provider::factory()->create();

        VendorUserLink::create([
            'user_id' => $alice->id,
            'provider_id' => $provider->id,
            'vendor_email' => 'ali@vendor.com',
        ]);

        // Bob tries to link same vendor email to same provider
        expect(fn () => VendorUserLink::create([
            'user_id' => $bob->id,
            'provider_id' => $provider->id,
            'vendor_email' => 'ali@vendor.com',
        ]))->toThrow(\Illuminate\Database\QueryException::class);
    });

    it('can find links by vendor email', function (): void {
        $alice = User::factory()->create();
        $alicia = User::factory()->create();
        $providerA = Provider::factory()->create();
        $providerB = Provider::factory()->create();

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

        // Different vendor email
        VendorUserLink::create([
            'user_id' => $alice->id,
            'provider_id' => $providerB->id,
            'vendor_email' => 'alice@other.com',
        ]);

        $links = VendorUserLink::where('vendor_email', 'ali@vendor.com')->get();

        expect($links)->toHaveCount(2);
    });

    it('can find link by vendor email and provider', function (): void {
        $alice = User::factory()->create();
        $alicia = User::factory()->create();
        $providerA = Provider::factory()->create();
        $providerB = Provider::factory()->create();

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

        $link = VendorUserLink::where('vendor_email', 'ali@vendor.com')
            ->where('provider_id', $providerA->id)
            ->first();

        expect($link)->not->toBeNull()
            ->and($link->user_id)->toBe($alice->id);
    });

    it('user has many vendor links', function (): void {
        $user = User::factory()->create();
        $providerA = Provider::factory()->create();
        $providerB = Provider::factory()->create();

        VendorUserLink::create([
            'user_id' => $user->id,
            'provider_id' => $providerA->id,
            'vendor_email' => 'ali@vendor.com',
        ]);

        VendorUserLink::create([
            'user_id' => $user->id,
            'provider_id' => $providerB->id,
            'vendor_email' => 'ali@vendor.com',
        ]);

        expect($user->vendorLinks)->toHaveCount(2);
    });

    it('provider has many vendor links', function (): void {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $provider = Provider::factory()->create();

        VendorUserLink::create([
            'user_id' => $alice->id,
            'provider_id' => $provider->id,
            'vendor_email' => 'alice@vendor.com',
        ]);

        VendorUserLink::create([
            'user_id' => $bob->id,
            'provider_id' => $provider->id,
            'vendor_email' => 'bob@vendor.com',
        ]);

        expect($provider->vendorLinks)->toHaveCount(2);
    });
});

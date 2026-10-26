<?php

declare(strict_types=1);

use App\Jobs\DeliverConsumerContactCode;
use App\Models\ConsumerIdentity;
use App\Models\ConsumerOtpDelivery;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    config(['pull2spend.consumer.enabled' => true, 'pull2spend.consumer.terms_version' => 'terms-1', 'pull2spend.consumer.privacy_notice_version' => 'privacy-1']);
});

it('registers verifies and logs in through real V2 routes without early authority', function (): void {
    Queue::fake([DeliverConsumerContactCode::class]);
    $response = $this->postJson('/api/v2/auth/registrations', [
        'display_name' => 'Mobile consumer', 'contact' => ['kind' => 'email', 'value' => 'mobile@example.invalid'],
        'password' => 'MobilePassword123!', 'password_confirmation' => 'MobilePassword123!', 'terms_version' => 'terms-1', 'privacy_notice_version' => 'privacy-1',
    ])->assertOk()->assertJsonPath('purpose', 'contact_verification')->assertJsonPath('methods.0', 'verified_channel_otp')->assertJsonMissingPath('access_token');
    $delivery = ConsumerOtpDelivery::sole();
    Queue::assertPushed(DeliverConsumerContactCode::class, fn ($job): bool => $job->deliveryId === $delivery->id);
    $this->assertDatabaseCount('users', 0);
    $this->postJson('/api/v2/auth/contact-verifications', ['challenge_reference' => $response->json('reference'), 'method' => 'verified_channel_otp', 'proof' => $delivery->payload['code']])
        ->assertOk()->assertJsonPath('verified', true)->assertJsonMissingPath('access_token');
    $challenge = $this->postJson('/api/v2/auth/challenges', ['contact' => ['kind' => 'email', 'value' => 'mobile@example.invalid'], 'method' => 'password'])->assertCreated();
    $login = $this->postJson('/api/v2/auth/sessions', ['challenge_reference' => $challenge->json('reference'), 'method' => 'password', 'proof' => 'MobilePassword123!'])->assertCreated();
    $this->withToken($login->json('access_token'))->getJson('/api/v2/eligibility')->assertOk()->assertJsonPath('kyc_state', 'not_started');
    $this->assertDatabaseCount('consumer_identities', 1);
});

it('rejects malformed contact and unmatched password confirmation with 422', function (): void {
    $this->postJson('/api/v2/auth/registrations', ['display_name' => 'Consumer', 'contact' => ['kind' => 'email', 'value' => 'invalid'], 'password' => 'SafePassword123!', 'password_confirmation' => 'different', 'terms_version' => 'terms-1', 'privacy_notice_version' => 'privacy-1'])
        ->assertUnprocessable()->assertJsonValidationErrors(['contact.value', 'password']);
    $this->assertDatabaseCount('consumer_challenges', 0);
});

it('cannot bypass pending V2 activation through legacy password login', function (): void {
    $user = User::factory()->create(['password' => 'SafePassword123!']);
    ConsumerIdentity::factory()->for($user)->create();
    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'SafePassword123!', 'device_name' => 'legacy-bypass'])
        ->assertUnprocessable();
    $this->assertDatabaseCount('personal_access_tokens', 0);
});

it('keeps ordinary legacy users compatible with V1 login', function (): void {
    $user = User::factory()->create(['password' => 'SafePassword123!']);
    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'SafePassword123!', 'device_name' => 'legacy'])
        ->assertOk()->assertJsonStructure(['token']);
});

it('limits registration requests before issuing unlimited contact deliveries', function (): void {
    Queue::fake([DeliverConsumerContactCode::class]);
    $input = ['display_name' => 'Consumer', 'contact' => ['kind' => 'email', 'value' => 'limited@example.invalid'], 'password' => 'SafePassword123!', 'password_confirmation' => 'SafePassword123!', 'terms_version' => 'terms-1', 'privacy_notice_version' => 'privacy-1'];
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v2/auth/registrations', $input)->assertOk();
    }
    $this->postJson('/api/v2/auth/registrations', $input)->assertTooManyRequests()->assertJsonPath('error.code', 'rate_limited');
    $this->assertDatabaseCount('consumer_otp_deliveries', 5);
});

it('returns a safe JSON authentication error even when the caller omits Accept', function (): void {
    $this->get('/api/v2/eligibility')->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated')->assertJsonMissingPath('exception');
});

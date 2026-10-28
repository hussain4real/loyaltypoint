<?php

declare(strict_types=1);

use App\Enums\KycState;
use App\Mail\ConsumerContactCodeMail;
use App\Models\ConsumerChallenge;
use App\Models\ConsumerOtpDelivery;
use App\Models\User;
use App\Services\ConsumerContactService;
use App\Services\ConsumerIdentityFailure;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    config(['pull2spend.consumer.enabled' => true, 'pull2spend.consumer.terms_version' => 'terms-1', 'pull2spend.consumer.privacy_notice_version' => 'privacy-1']);
});

function consumerRegistrationInput(string $email = 'new@example.invalid'): array
{
    return ['display_name' => 'New Consumer', 'contact' => ['kind' => 'email', 'value' => $email], 'password' => 'SafePassword-1234!', 'password_confirmation' => 'SafePassword-1234!', 'terms_version' => 'terms-1', 'privacy_notice_version' => 'privacy-1'];
}

it('keeps pending registration secrets encrypted without creating a user or granting authority', function (): void {
    $challenge = app(ConsumerContactService::class)->register(consumerRegistrationInput());
    $delivery = ConsumerOtpDelivery::where('challenge_id', $challenge->id)->sole();
    expect(Hash::check($delivery->payload['code'], $challenge->proof_hash))->toBeTrue();
    expect(DB::table('consumer_challenges')->value('payload'))->not->toContain('new@example.invalid', 'SafePassword-1234!');
    expect(DB::table('consumer_otp_deliveries')->value('payload'))->not->toContain($delivery->payload['code'], 'new@example.invalid');
    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('consumer_identities', 0);
    $this->assertDatabaseCount('personal_access_tokens', 0);
});

it('activates exactly one contact verified identity without creating a wallet card or session', function (): void {
    $service = app(ConsumerContactService::class);
    $challenge = $service->register(consumerRegistrationInput('NEW@example.invalid'));
    $code = ConsumerOtpDelivery::where('challenge_id', $challenge->id)->sole()->payload['code'];
    $identity = $service->verify($challenge->id, $code);
    expect($identity->contact_verified_at)->not->toBeNull();
    expect($identity->kyc_state)->toBe(KycState::NotStarted);
    expect($identity->user->email)->toBe('new@example.invalid');
    expect(Hash::check('SafePassword-1234!', $identity->user->password))->toBeTrue();
    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseCount('consumer_identities', 1);
    $this->assertDatabaseCount('personal_access_tokens', 0);
    expect($challenge->fresh()->payload)->toBeNull();
});

it('commits failed attempts and rejects a correct code after exhaustion', function (): void {
    $service = app(ConsumerContactService::class);
    $challenge = $service->register(consumerRegistrationInput());
    $correct = ConsumerOtpDelivery::where('challenge_id', $challenge->id)->sole()->payload['code'];
    $wrong = $correct === '000000' ? '999999' : '000000';
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        expect(fn () => $service->verify($challenge->id, $wrong))->toThrow(ConsumerIdentityFailure::class);
        expect($challenge->fresh()->attempts)->toBe($attempt);
    }
    expect(fn () => $service->verify($challenge->id, $correct))->toThrow(ConsumerIdentityFailure::class, 'challenge_attempts_exceeded');
    $this->assertDatabaseCount('users', 0);
});

it('rejects contact proof exactly at expiry', function (): void {
    $this->freezeTime();
    $service = app(ConsumerContactService::class);
    $challenge = $service->register(consumerRegistrationInput());
    $code = ConsumerOtpDelivery::where('challenge_id', $challenge->id)->sole()->payload['code'];
    $this->travelTo($challenge->expires_at);
    expect(fn () => $service->verify($challenge->id, $code))->toThrow(ConsumerIdentityFailure::class, 'challenge_expired');
    $this->assertDatabaseCount('users', 0);
});

it('rejects replay after successful activation', function (): void {
    $service = app(ConsumerContactService::class);
    $challenge = $service->register(consumerRegistrationInput());
    $code = ConsumerOtpDelivery::where('challenge_id', $challenge->id)->sole()->payload['code'];
    $service->verify($challenge->id, $code);
    expect(fn () => $service->verify($challenge->id, $code))->toThrow(ConsumerIdentityFailure::class, 'challenge_replayed');
    $this->assertDatabaseCount('users', 1);
});

it('cannot use another purpose as contact activation', function (): void {
    $challenge = ConsumerChallenge::factory()->create(['purpose' => 'login', 'method' => 'password']);
    expect(fn () => app(ConsumerContactService::class)->verify($challenge->id, '123456'))->toThrow(ConsumerIdentityFailure::class, 'challenge_invalid');
    expect($challenge->fresh()->consumed_at)->toBeNull();
    $this->assertDatabaseCount('users', 0);
});

it('does not reveal or overwrite an existing account through registration', function (): void {
    $user = User::factory()->create(['email' => 'new@example.invalid']);
    $original = $user->password;
    $challenge = app(ConsumerContactService::class)->register(consumerRegistrationInput());
    expect($challenge->purpose)->toBe('contact_verification');
    $this->assertDatabaseCount('consumer_otp_deliveries', 0);
    expect(fn () => app(ConsumerContactService::class)->verify($challenge->id, '123456'))->toThrow(ConsumerIdentityFailure::class);
    expect($user->fresh()->password)->toBe($original);
    $this->assertDatabaseCount('consumer_identities', 0);
});

it('cannot replace credentials through a second verified registration intent', function (): void {
    $service = app(ConsumerContactService::class);
    $first = $service->register(consumerRegistrationInput());
    $secondInput = consumerRegistrationInput();
    $secondInput['password'] = $secondInput['password_confirmation'] = 'AnotherPassword-1234!';
    $this->travel(60)->seconds();
    $second = $service->register($secondInput);
    $code1 = ConsumerOtpDelivery::where('challenge_id', $first->id)->sole()->payload['code'];
    $code2 = ConsumerOtpDelivery::where('challenge_id', $second->id)->sole()->payload['code'];
    $identity = $service->verify($first->id, $code1);
    expect(fn () => $service->verify($second->id, $code2))->toThrow(ConsumerIdentityFailure::class);
    expect(Hash::check('SafePassword-1234!', $identity->user->fresh()->password))->toBeTrue();
    $this->assertDatabaseCount('users', 1);
});

it('fails closed when notices or admission are unavailable', function (string $setting, mixed $value): void {
    config([$setting => $value]);
    expect(fn () => app(ConsumerContactService::class)->register(consumerRegistrationInput()))->toThrow(ConsumerIdentityFailure::class);
    $this->assertDatabaseCount('consumer_challenges', 0);
})->with(['disabled' => ['pull2spend.consumer.enabled', false], 'terms missing' => ['pull2spend.consumer.terms_version', null], 'privacy missing' => ['pull2spend.consumer.privacy_notice_version', null], 'changed terms' => ['pull2spend.consumer.terms_version', 'terms-2']]);

it('rotates a resend challenge and prevents the old code from activation', function (): void {
    $service = app(ConsumerContactService::class);
    $old = $service->register(consumerRegistrationInput());
    $code = ConsumerOtpDelivery::where('challenge_id', $old->id)->sole()->payload['code'];
    $this->travel(60)->seconds();
    $new = $service->resend($old->id);
    expect($new->id)->not->toBe($old->id);
    expect($old->fresh()->revoked_at)->not->toBeNull();
    expect(fn () => $service->verify($old->id, $code))->toThrow(ConsumerIdentityFailure::class);
});

it('expires pending email without sending stale proof', function (): void {
    Mail::fake();
    $service = app(ConsumerContactService::class);
    $challenge = $service->register(consumerRegistrationInput());
    $delivery = ConsumerOtpDelivery::where('challenge_id', $challenge->id)->sole();
    $this->travelTo($delivery->expires_at);
    $service->deliver($delivery->id);
    Mail::assertNothingSent();
    expect($delivery->fresh()->state)->toBe('expired');
    expect($delivery->fresh()->payload)->toBeNull();
});

it('sends the referenced code once and removes recoverable secret data after delivery', function (): void {
    Mail::fake();
    $service = app(ConsumerContactService::class);
    $challenge = $service->register(consumerRegistrationInput());
    $delivery = ConsumerOtpDelivery::where('challenge_id', $challenge->id)->sole();
    $code = $delivery->payload['code'];
    $service->deliver($delivery->id);
    $service->deliver($delivery->id);
    Mail::assertSent(ConsumerContactCodeMail::class, fn ($mail): bool => $mail->hasTo('new@example.invalid') && $mail->code === $code);
    Mail::assertSentCount(1);
    expect($delivery->fresh()->state)->toBe('sent');
    expect($delivery->fresh()->payload)->toBeNull();
    expect($challenge->fresh()->consumed_at)->toBeNull();
});

it('retains activation proof when email transport fails without exposing transport details', function (): void {
    $service = app(ConsumerContactService::class);
    $challenge = $service->register(consumerRegistrationInput());
    $delivery = ConsumerOtpDelivery::where('challenge_id', $challenge->id)->sole();
    $code = $delivery->payload['code'];
    Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('sensitive transport details'));
    $service->deliver($delivery->id);
    expect($delivery->fresh()->state)->toBe('pending');
    expect($delivery->fresh()->last_error_code)->toBe('contact_delivery_unavailable');
    expect($service->verify($challenge->id, $code)->contact_verified_at)->not->toBeNull();
});

it('recovers abandoned terminal delivery as one owned notification case', function (): void {
    $this->freezeTime();
    $delivery = ConsumerOtpDelivery::factory()->create(['state' => 'processing', 'attempts' => 5, 'lease_expires_at' => now()->subSecond()]);
    $this->artisan('consumer:recover-contact-codes')->assertSuccessful();
    $this->artisan('consumer:recover-contact-codes')->assertSuccessful();
    expect($delivery->fresh()->state)->toBe('failed');
    $this->assertDatabaseCount('operational_cases', 1);
});

it('does not overwrite a delivery completed after recovery selected its stale lease', function (): void {
    $this->freezeTime();
    $delivery = ConsumerOtpDelivery::factory()->create(['state' => 'processing', 'attempts' => 5, 'lease_token' => (string) Str::uuid(), 'lease_expires_at' => now()->subSecond()]);
    ConsumerOtpDelivery::retrieved(function (ConsumerOtpDelivery $snapshot) use ($delivery): void {
        if ($snapshot->id === $delivery->id && $snapshot->state === 'processing') {
            DB::table('consumer_otp_deliveries')->where('id', $delivery->id)->update(['state' => 'sent', 'completed_at' => now(), 'payload' => null, 'lease_token' => null, 'lease_expires_at' => null]);
        }
    });
    $this->artisan('consumer:recover-contact-codes')->assertSuccessful();
    expect($delivery->fresh()->state)->toBe('sent');
    $this->assertDatabaseCount('operational_cases', 0);
});

it('clears the recoverable OTP immediately after contact activation without sending queued mail', function (): void {
    Mail::fake();
    $service = app(ConsumerContactService::class);
    $challenge = $service->register(consumerRegistrationInput());
    $delivery = ConsumerOtpDelivery::where('challenge_id', $challenge->id)->sole();
    $service->verify($challenge->id, $delivery->payload['code']);
    expect($delivery->fresh()->payload)->toBeNull();
    $service->deliver($delivery->id);
    Mail::assertNothingSent();
});

it('clears terminally failed contact secrets and leaves one owned notification case', function (): void {
    $service = app(ConsumerContactService::class);
    $challenge = $service->register(consumerRegistrationInput());
    $delivery = ConsumerOtpDelivery::where('challenge_id', $challenge->id)->sole();
    $delivery->update(['attempts' => 4]);
    Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('private transport detail'));
    $service->deliver($delivery->id);
    expect($delivery->fresh()->state)->toBe('failed');
    expect($delivery->fresh()->payload)->toBeNull();
    $this->assertDatabaseCount('operational_cases', 1);
});

it('does not deliver credentials through a logging mailer outside tests', function (): void {
    Mail::fake();
    config(['mail.default' => 'unsafe-log', 'mail.mailers.unsafe-log' => ['transport' => 'log']]);
    app()->detectEnvironment(fn (): string => 'local');
    $service = app(ConsumerContactService::class);
    $challenge = $service->register(consumerRegistrationInput());
    $delivery = ConsumerOtpDelivery::where('challenge_id', $challenge->id)->sole();
    $service->deliver($delivery->id);
    Mail::assertNothingSent();
    expect($delivery->fresh()->state)->toBe('pending');
    expect($delivery->fresh()->last_error_code)->toBe('contact_delivery_unavailable');
});

it('erases unused registration credentials at expiry while preserving other challenge purposes', function (): void {
    $this->freezeTime();
    $service = app(ConsumerContactService::class);
    $challenge = $service->register(consumerRegistrationInput());
    $delivery = ConsumerOtpDelivery::where('challenge_id', $challenge->id)->sole();
    $securityChallenge = ConsumerChallenge::factory()->create(['purpose' => 'login', 'method' => 'password', 'payload' => ['message' => 'public-nonce'], 'expires_at' => $challenge->expires_at]);
    $securityPayload = DB::table('consumer_challenges')->where('id', $securityChallenge->id)->value('payload');
    $this->travelTo($challenge->expires_at);
    $this->artisan('consumer:recover-contact-codes')->assertSuccessful();
    expect($challenge->fresh()->payload === null)->toBeTrue();
    expect($challenge->fresh()->proof_hash === null)->toBeTrue();
    expect($challenge->fresh()->revoked_at)->not->toBeNull();
    expect($delivery->fresh()->payload === null)->toBeTrue();
    expect(fn () => $service->resend($challenge->id))->toThrow(ConsumerIdentityFailure::class, 'challenge_invalid');
    expect($securityChallenge->fresh()->revoked_at)->toBeNull();
    expect(DB::table('consumer_challenges')->where('id', $securityChallenge->id)->value('payload'))->toBe($securityPayload);
    $this->assertDatabaseCount('users', 0);
});

it('shares contact request controls across registration and resend instead of resetting the budget', function (): void {
    config(['pull2spend.consumer.registration_requests_per_minute' => 2, 'pull2spend.consumer.contact_resend_seconds' => 0]);
    $service = app(ConsumerContactService::class);
    $first = $service->register(consumerRegistrationInput());
    $second = $service->resend($first->id);
    expect(fn () => $service->resend($second->id))->toThrow(ConsumerIdentityFailure::class, 'rate_limited');
    expect($second->fresh()->revoked_at)->toBeNull();
    $this->assertDatabaseCount('consumer_challenges', 2);
});

it('rejects a composite mailer that could fall back to logging credentials', function (): void {
    Mail::fake();
    config(['mail.default' => 'unsafe-failover', 'mail.mailers.unsafe-failover' => ['transport' => 'failover', 'mailers' => ['smtp', 'log']]]);
    app()->detectEnvironment(fn (): string => 'local');
    $service = app(ConsumerContactService::class);
    $challenge = $service->register(consumerRegistrationInput());
    $delivery = ConsumerOtpDelivery::where('challenge_id', $challenge->id)->sole();
    $service->deliver($delivery->id);
    Mail::assertNothingSent();
    expect($delivery->fresh()->state)->toBe('pending');
});

it('enforces the resend interval across resend and repeated registration without revoking the active proof', function (): void {
    $this->freezeTime();
    $service = app(ConsumerContactService::class);
    $challenge = $service->register(consumerRegistrationInput());
    expect(fn () => $service->resend($challenge->id))->toThrow(ConsumerIdentityFailure::class, 'contact_resend_too_soon');
    expect(fn () => $service->register(consumerRegistrationInput()))->toThrow(ConsumerIdentityFailure::class, 'contact_resend_too_soon');
    $this->assertDatabaseCount('consumer_challenges', 1);
    expect($challenge->fresh()->revoked_at)->toBeNull();
    $this->travel(60)->seconds();
    $replacement = $service->resend($challenge->id);
    expect($replacement->id)->not->toBe($challenge->id);
    expect($challenge->fresh()->revoked_at)->not->toBeNull();
});

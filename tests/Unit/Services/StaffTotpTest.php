<?php

declare(strict_types=1);

use App\Services\StaffTotp;

it('creates independent authenticator secrets with the supported Base32 key length', function (): void {
    $totp = new StaffTotp;
    $first = $totp->createSecret();
    $second = $totp->createSecret();

    expect($first)->toMatch('/\A[A-Z2-7]{32}\z/')->not->toBe($second);
    expect($second)->toMatch('/\A[A-Z2-7]{32}\z/');
});

it('accepts only the current or immediately adjacent time step', function (int $timestamp, ?int $counter): void {
    $totp = new StaffTotp;
    $at = new DateTimeImmutable('@'.$timestamp);

    expect($totp->matchingCounter('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', '287082', $at))->toBe($counter);
})->with([
    [0, 1],
    [59, 1],
    [89, 1],
    [90, null],
]);

it('rejects invalid secrets and malformed authenticator codes', function (?string $secret, string $code): void {
    expect((new StaffTotp)->matchingCounter($secret, $code, new DateTimeImmutable('@59')))->toBeNull();
})->with([
    [null, '287082'],
    ['', '287082'],
    ['invalid!', '287082'],
    ['GEZDGNBVGY3TQOJQ', '287082'],
    ['GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', '28708'],
    ['GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', '2870820'],
    ['GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', '287082 '],
    ['GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', '000000'],
]);

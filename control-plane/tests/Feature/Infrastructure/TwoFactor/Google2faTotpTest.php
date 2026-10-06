<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Domain\Identity\TwoFactor\Totp;
use PragmaRX\Google2FA\Google2FA;

// Issue #25: TOTP verification with replay protection.

const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

function currentStep(): int
{
    return intdiv(now()->getTimestamp(), 30);
}

function codeAt(int $step): string
{
    return (new Google2FA)->oathTotp(SECRET, $step);
}

beforeEach(function (): void {
    $this->freezeTime();
});

it('generates a 32-character Base32 secret, different every time', function (): void {
    $totp = app(Totp::class);

    expect($totp->generateSecret())->toMatch('/^[A-Z2-7]{32}$/')
        ->and($totp->generateSecret())->not->toBe($totp->generateSecret());
});

it('builds an otpauth URI with the issuer, the account and the secret', function (): void {
    $uri = app(Totp::class)->provisioningUri('Hostmesh', 'jane@example.com', SECRET);

    expect($uri)->toStartWith('otpauth://totp/')
        ->and($uri)->toContain('secret='.SECRET)
        ->and($uri)->toContain('issuer=Hostmesh')
        ->and(rawurldecode($uri))->toContain('jane@example.com');
});

it('accepts the current code and returns its time step', function (): void {
    expect(app(Totp::class)->verify(SECRET, codeAt(currentStep()), null))->toBe(currentStep());
});

it('refuses a code that was already accepted', function (): void {
    $totp = app(Totp::class);
    $code = codeAt(currentStep());

    $step = $totp->verify(SECRET, $code, null);

    expect($totp->verify(SECRET, $code, $step))->toBeNull();
});

it('accepts the next code after one was used', function (): void {
    $totp = app(Totp::class);
    $step = $totp->verify(SECRET, codeAt(currentStep()), null);

    $this->travel(30)->seconds();

    expect($totp->verify(SECRET, codeAt(currentStep()), $step))->toBe($step + 1);
});

it('tolerates one step of clock drift either way and no more', function (int $offset, bool $accepted): void {
    $result = app(Totp::class)->verify(SECRET, codeAt(currentStep() + $offset), null);

    expect($result !== null)->toBe($accepted);
})->with([
    'two steps behind' => [-2, false],
    'one step behind' => [-1, true],
    'one step ahead' => [1, true],
    'two steps ahead' => [2, false],
]);

it('refuses an older step once a newer one was accepted', function (): void {
    $totp = app(Totp::class);
    $step = $totp->verify(SECRET, codeAt(currentStep()), null);

    expect($totp->verify(SECRET, codeAt(currentStep() - 1), $step))->toBeNull();
});

it('refuses anything that is not six digits', function (string $code): void {
    expect(app(Totp::class)->verify(SECRET, $code, null))->toBeNull();
})->with(['', '12345', '1234567', 'abcdef', '12 345', "123456\n"]);

it('refuses a code of another secret', function (): void {
    $other = (new Google2FA)->oathTotp('KRSXG5CTMVRXEZLUKRSXG5CTMVRXEZLU', currentStep());

    expect(app(Totp::class)->verify(SECRET, $other, null))->toBeNull();
});

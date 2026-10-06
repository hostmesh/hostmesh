<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Infrastructure\TwoFactor;

use App\Domain\Identity\TwoFactor\Totp;
use PragmaRX\Google2FA\Exceptions\IncompatibleWithGoogleAuthenticatorException;
use PragmaRX\Google2FA\Exceptions\InvalidCharactersException;
use PragmaRX\Google2FA\Exceptions\SecretKeyTooShortException;
use PragmaRX\Google2FA\Google2FA;
use SensitiveParameter;

/**
 * TOTP on pragmarx/google2fa (decision 0011). The time is the application's
 * clock, not the library's, so tests can move it.
 */
final readonly class Google2faTotp implements Totp
{
    // Steps of tolerance either way, for clock drift.
    protected const int WINDOW = 1;

    public function __construct(
        protected Google2FA $engine,
    ) {
        //
    }

    /**
     * @throws IncompatibleWithGoogleAuthenticatorException
     * @throws SecretKeyTooShortException
     * @throws InvalidCharactersException
     */
    public function generateSecret(): string
    {
        return $this->engine->generateSecretKey(32);
    }

    public function provisioningUri(string $issuer, string $account, #[SensitiveParameter] string $secret): string
    {
        return $this->engine->getQRCodeUrl($issuer, $account, $secret);
    }

    /**
     * @throws IncompatibleWithGoogleAuthenticatorException
     * @throws SecretKeyTooShortException
     * @throws InvalidCharactersException
     */
    public function verify(
        #[SensitiveParameter] string $secret,
        #[SensitiveParameter] string $code,
        ?int $afterStep
    ): ?int {
        // \z, not $: a trailing newline is not part of a code.
        if (preg_match('/^\d{6}\z/', $code) !== 1) {
            return null;
        }

        $now = intdiv(now()->getTimestamp(), $this->engine->getKeyRegeneration());

        // verifyKeyNewer() checks the steps from max($now - WINDOW,
        // $oldTimestamp + 1) to $now + WINDOW and returns the one that
        // matched. Without an earlier step the whole window counts.
        $step = $this->engine->verifyKeyNewer(
            $secret,
            $code,
            $afterStep ?? $now - self::WINDOW - 1,
            self::WINDOW,
            $now,
        );

        return is_int($step) ? $step : null;
    }
}

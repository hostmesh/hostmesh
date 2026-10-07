<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Domain\Identity\TwoFactor;

use SensitiveParameter;

/**
 * Two-factor state of an account (issue #25). Callers that accept a code
 * work on lockedCopy() inside a transaction, so one code cannot be spent by
 * two requests at once.
 */
trait HasTwoFactor
{
    public function initializeHasTwoFactor(): void
    {
        $this->mergeCasts([
            'two_factor_secret' => 'encrypted',
            'two_factor_confirmed_at' => 'immutable_datetime',
            'two_factor_last_step' => 'integer',
            'two_factor_recovery_codes' => 'array',
        ]);
    }

    public function hasTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    /** This account, read again under a row lock. Call inside a transaction. */
    public function lockedCopy(): static
    {
        return static::query()->lockForUpdate()->whereKey($this->getKey())->firstOrFail();
    }

    /** Stores a new, unconfirmed secret. Replaces an abandoned enrollment. */
    public function beginTwoFactorEnrollment(#[SensitiveParameter] string $secret): void
    {
        $this->two_factor_secret = $secret;
        $this->two_factor_confirmed_at = null;
        $this->two_factor_last_step = null;
        $this->two_factor_recovery_codes = null;
        $this->save();
    }

    /** @param  list<string>  $recoveryCodeHashes */
    public function confirmTwoFactor(array $recoveryCodeHashes): void
    {
        $this->two_factor_confirmed_at = now()->toImmutable();
        $this->two_factor_recovery_codes = $recoveryCodeHashes;
        $this->save();
    }

    /** Accepts the code and remembers its time step, or returns false. */
    public function acceptTotpCode(Totp $totp, #[SensitiveParameter] string $code): bool
    {
        if ($this->two_factor_secret === null) {
            return false;
        }

        $step = $totp->verify($this->two_factor_secret, $code, $this->two_factor_last_step);

        if ($step === null) {
            return false;
        }

        $this->two_factor_last_step = $step;
        $this->save();

        return true;
    }

    /** Removes the code with this hash. False when there is none. */
    public function consumeRecoveryCode(string $hash): bool
    {
        $stored = $this->two_factor_recovery_codes ?? [];

        $remaining = array_values(array_filter(
            $stored,
            fn (string $candidate): bool => ! hash_equals($candidate, $hash),
        ));

        if (count($remaining) === count($stored)) {
            return false;
        }

        $this->two_factor_recovery_codes = $remaining;
        $this->save();

        return true;
    }

    /** @param  list<string>  $recoveryCodeHashes */
    public function replaceRecoveryCodes(array $recoveryCodeHashes): void
    {
        $this->two_factor_recovery_codes = $recoveryCodeHashes;
        $this->save();
    }

    public function disableTwoFactor(): void
    {
        $this->two_factor_secret = null;
        $this->two_factor_confirmed_at = null;
        $this->two_factor_last_step = null;
        $this->two_factor_recovery_codes = null;
        $this->save();
    }
}

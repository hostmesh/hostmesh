<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Commands;

use App\Application\Exceptions\InvalidTwoFactorCode;
use App\Application\Services\SecondFactorSession;
use App\Domain\Identity\Administrator;
use App\Domain\Identity\TwoFactor\RecoveryCodes;
use App\Domain\Identity\TwoFactor\Totp;
use App\Domain\Identity\User;
use SensitiveParameter;

final readonly class CompleteTwoFactorChallenge
{
    public function __construct(
        protected Totp $totp,
        protected RecoveryCodes $recoveryCodes,
        protected SecondFactorSession $session,
    ) {
        //
    }

    /**
     * Completes the second factor for the current session with a TOTP code
     * or, when $code is null, with a recovery code, which is then spent.
     *
     * @throws InvalidTwoFactorCode
     */
    public function handle(
        Administrator|User $account,
        #[SensitiveParameter] ?string $code,
        #[SensitiveParameter] ?string $recoveryCode,
    ): void {
        $accepted = $account->getConnection()->transaction(function () use ($account, $code, $recoveryCode): bool {
            $locked = $account->lockedCopy();

            if (! $locked->hasTwoFactor()) {
                return false;
            }

            if ($code !== null) {
                return $locked->acceptTotpCode($this->totp, $code);
            }

            return $recoveryCode !== null
                && $locked->consumeRecoveryCode($this->recoveryCodes->hash($recoveryCode));
        });

        if (! $accepted) {
            throw new InvalidTwoFactorCode;
        }

        $this->session->mark($account);
    }
}

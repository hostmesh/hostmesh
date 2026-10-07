<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Commands;

use App\Application\Exceptions\IncorrectPassword;
use App\Application\Exceptions\InvalidTwoFactorCode;
use App\Application\Services\SecondFactorSession;
use App\Domain\Identity\Administrator;
use App\Domain\Identity\TwoFactor\Totp;
use App\Domain\Identity\User;
use Illuminate\Contracts\Hashing\Hasher;
use SensitiveParameter;
use Throwable;

final readonly class DisableTwoFactor
{
    public function __construct(
        protected Totp $totp,
        protected Hasher $hasher,
        protected SecondFactorSession $session,
    ) {
        //
    }

    /**
     * Removes the secret and the recovery codes. The password is checked
     * first, so a wrong password does not spend the code.
     *
     * @throws IncorrectPassword
     * @throws InvalidTwoFactorCode
     * @throws Throwable
     */
    public function handle(
        Administrator|User $account,
        #[SensitiveParameter] string $password,
        #[SensitiveParameter] string $code,
    ): void {
        if (! $this->hasher->check($password, $account->password)) {
            throw new IncorrectPassword;
        }

        $disabled = $account->getConnection()->transaction(function () use ($account, $code): bool {
            $locked = $account->lockedCopy();

            if (! $locked->hasTwoFactor() || ! $locked->acceptTotpCode($this->totp, $code)) {
                return false;
            }

            $locked->disableTwoFactor();

            return true;
        });

        if (! $disabled) {
            throw new InvalidTwoFactorCode;
        }

        $this->session->clear();
    }
}

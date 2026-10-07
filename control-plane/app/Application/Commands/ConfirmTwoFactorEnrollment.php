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
use Throwable;

final readonly class ConfirmTwoFactorEnrollment
{
    public function __construct(
        protected Totp $totp,
        protected RecoveryCodes $recoveryCodes,
        protected SecondFactorSession $session,
    ) {
        //
    }

    /**
     * Turns two-factor on and marks the session as having completed it.
     *
     * @return list<string> The recovery codes. They cannot be read again.
     *
     * @throws InvalidTwoFactorCode
     * @throws Throwable
     */
    public function handle(Administrator|User $account, #[SensitiveParameter] string $code): array
    {
        $codes = $account->getConnection()->transaction(function () use ($account, $code): ?array {
            $locked = $account->lockedCopy();

            if ($locked->hasTwoFactor() || ! $locked->acceptTotpCode($this->totp, $code)) {
                return null;
            }

            $codes = $this->recoveryCodes->generate();
            $locked->confirmTwoFactor(array_map($this->recoveryCodes->hash(...), $codes));

            return $codes;
        });

        if ($codes === null) {
            throw new InvalidTwoFactorCode;
        }

        $this->session->mark($account);

        return $codes;
    }
}

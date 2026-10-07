<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Commands;

use App\Application\Exceptions\IncorrectPassword;
use App\Application\Exceptions\InvalidTwoFactorCode;
use App\Domain\Identity\Administrator;
use App\Domain\Identity\TwoFactor\RecoveryCodes;
use App\Domain\Identity\TwoFactor\Totp;
use App\Domain\Identity\User;
use Illuminate\Contracts\Hashing\Hasher;
use SensitiveParameter;
use Throwable;

final readonly class RegenerateRecoveryCodes
{
    public function __construct(
        protected Totp $totp,
        protected RecoveryCodes $recoveryCodes,
        protected Hasher $hasher,
    ) {
        //
    }

    /**
     * Replaces every recovery code. The password is checked first, so a
     * wrong password does not spend the code.
     *
     * @return list<string> The new codes. They cannot be read again.
     *
     * @throws IncorrectPassword
     * @throws InvalidTwoFactorCode
     * @throws Throwable
     */
    public function handle(
        Administrator|User $account,
        #[SensitiveParameter] string $password,
        #[SensitiveParameter] string $code,
    ): array {
        if (! $this->hasher->check($password, $account->password)) {
            throw new IncorrectPassword;
        }

        $codes = $account->getConnection()->transaction(function () use ($account, $code): ?array {
            $locked = $account->lockedCopy();

            if (! $locked->hasTwoFactor() || ! $locked->acceptTotpCode($this->totp, $code)) {
                return null;
            }

            $codes = $this->recoveryCodes->generate();
            $locked->replaceRecoveryCodes(array_map($this->recoveryCodes->hash(...), $codes));

            return $codes;
        });

        return $codes ?? throw new InvalidTwoFactorCode;
    }
}

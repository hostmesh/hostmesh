<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Commands;

use App\Application\Exceptions\TwoFactorAlreadyEnabled;
use App\Domain\Identity\Administrator;
use App\Domain\Identity\TwoFactor\Totp;
use App\Domain\Identity\User;
use Illuminate\Contracts\Config\Repository;
use Throwable;

final readonly class StartTwoFactorEnrollment
{
    public function __construct(
        protected Totp $totp,
        protected Repository $config,
    ) {
        //
    }

    /**
     * Stores a new unconfirmed secret. Two-factor is not on until the
     * account confirms it with a code.
     *
     * @throws TwoFactorAlreadyEnabled
     * @throws Throwable
     */
    public function handle(Administrator|User $account): TwoFactorEnrollment
    {
        $secret = $this->totp->generateSecret();

        // On a locked copy: $account was read before this request, and a
        // confirmation since then must not have its secret replaced.
        $started = $account->getConnection()->transaction(function () use ($account, $secret): bool {
            $locked = $account->lockedCopy();

            if ($locked->hasTwoFactor()) {
                return false;
            }

            $locked->beginTwoFactorEnrollment($secret);

            return true;
        });

        if (! $started) {
            throw new TwoFactorAlreadyEnabled;
        }

        $issuer = $this->config->get('app.name');

        return new TwoFactorEnrollment(
            $secret,
            $this->totp->provisioningUri(is_string($issuer) ? $issuer : 'Hostmesh', $account->email, $secret),
        );
    }
}

<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Commands;

use App\Application\Exceptions\TwoFactorAlreadyEnabled;
use App\Domain\Identity\Administrator;
use App\Domain\Identity\TwoFactor\Totp;
use App\Domain\Identity\User;
use Illuminate\Contracts\Config\Repository;

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
     */
    public function handle(Administrator|User $account): TwoFactorEnrollment
    {
        if ($account->hasTwoFactor()) {
            throw new TwoFactorAlreadyEnabled;
        }

        $secret = $this->totp->generateSecret();
        $account->beginTwoFactorEnrollment($secret);

        $issuer = $this->config->get('app.name');

        return new TwoFactorEnrollment(
            $secret,
            $this->totp->provisioningUri(is_string($issuer) ? $issuer : 'Hostmesh', $account->email, $secret),
        );
    }
}

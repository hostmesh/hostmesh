<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Commands;

use App\Application\Exceptions\InvalidCredentials;
use App\Application\Services\SecondFactorSession;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory;
use Illuminate\Contracts\Auth\StatefulGuard;
use SensitiveParameter;

final readonly class LogIn
{
    public function __construct(
        protected Factory $auth,
        protected SecondFactorSession $secondFactor,
    ) {
        //
    }

    /**
     * Logs the account in on the given session guard. The guard regenerates
     * the session ID, and its timebox makes an unknown email take as long
     * as a wrong password.
     *
     * @throws InvalidCredentials
     */
    public function handle(string $guard, string $email, #[SensitiveParameter] string $password): Authenticatable
    {
        $session = $this->auth->guard($guard);

        if (! $session instanceof StatefulGuard) {
            throw new InvalidCredentials;
        }

        if (! $session->attempt(['email' => mb_strtolower($email), 'password' => $password])) {
            throw new InvalidCredentials;
        }

        // A password alone never completes the second factor (issue #25).
        $this->secondFactor->clear();

        return $session->user() ?? throw new InvalidCredentials;
    }
}

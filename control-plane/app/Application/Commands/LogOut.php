<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Commands;

use Illuminate\Contracts\Auth\Factory;
use Illuminate\Contracts\Auth\StatefulGuard;

final readonly class LogOut
{
    public function __construct(
        protected Factory $auth,
    ) {
        //
    }

    public function handle(string $guard): void
    {
        $session = $this->auth->guard($guard);

        if ($session instanceof StatefulGuard) {
            $session->logout();
        }
    }
}

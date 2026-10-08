<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Commands;

use App\Application\Events\LoggedOut;
use App\Domain\Identity\Administrator;
use App\Domain\Identity\User;
use Illuminate\Contracts\Auth\Factory;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Events\Dispatcher;

final readonly class LogOut
{
    public function __construct(
        protected Factory $auth,
        protected Dispatcher $events,
    ) {
        //
    }

    /**
     * Ends the session of the given guard. The audit event is written
     * first: if it cannot be, the session stays (decision 0013).
     */
    public function handle(string $guard): void
    {
        $session = $this->auth->guard($guard);

        if (! $session instanceof StatefulGuard) {
            return;
        }

        $account = $session->user();

        if ($account instanceof Administrator || $account instanceof User) {
            $this->events->dispatch(new LoggedOut($account));
        }

        $session->logout();
    }
}

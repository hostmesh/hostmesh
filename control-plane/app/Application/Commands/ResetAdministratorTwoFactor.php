<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Commands;

use App\Application\Events\AdministratorTwoFactorReset;
use App\Application\Exceptions\AdministratorNotFound;
use App\Application\Exceptions\TwoFactorNotEnabled;
use App\Domain\Identity\Administrator;
use App\Support\Realms;
use Illuminate\Contracts\Events\Dispatcher;
use Throwable;

final readonly class ResetAdministratorTwoFactor
{
    public function __construct(
        protected Dispatcher $events,
        protected Realms $realms,
    ) {
        //
    }

    /**
     * Removes the second factor of an administrator who lost both the
     * authenticator and the recovery codes (issue #136, spec section 28),
     * ends the administrator's sessions and audits the reset, in one
     * transaction (decision 0013). Without the sessions ended, a session
     * that had passed the second factor could enroll a new one without the
     * password. The next login leads into enrollment.
     *
     * @throws AdministratorNotFound
     * @throws TwoFactorNotEnabled when there is nothing to reset: nothing changed, nothing is audited
     * @throws Throwable
     */
    public function handle(string $email): void
    {
        $email = mb_strtolower($email);
        $connection = (new Administrator)->getConnection();

        $connection->transaction(function () use ($connection, $email): void {
            // The model stores addresses in lower case; so is the search.
            $administrator = Administrator::query()->lockForUpdate()->where('email', $email)->first()
                ?? throw AdministratorNotFound::withEmail($email);

            if (! $administrator->hasTwoFactor()) {
                throw TwoFactorNotEnabled::forAdministrator($email);
            }

            $administrator->disableTwoFactor();

            $connection->table($this->realms->get('admin')->sessionTable)
                ->where('user_id', $administrator->id)
                ->delete();

            $this->events->dispatch(new AdministratorTwoFactorReset($administrator));
        });
    }
}

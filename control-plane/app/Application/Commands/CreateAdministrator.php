<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Commands;

use App\Application\Events\AdministratorCreated;
use App\Application\Exceptions\AdministratorExists;
use App\Domain\Authorization\Role;
use App\Domain\Identity\Administrator;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Throwable;

final readonly class CreateAdministrator
{
    /** Long enough that no rule about its content is needed. */
    public const int PASSWORD_LENGTH = 40;

    public function __construct(
        protected Dispatcher $events,
        protected AssignAdministratorRole $assignRole,
    ) {
        //
    }

    /**
     * Creates an administrator with a role and a generated password (issue
     * #122), and audits the creation and the role in the same transaction
     * (decision 0013). The account has no second factor: its first login
     * leads into enrollment. Whether $actor may do this is the caller's
     * policy check, not this command's.
     *
     * @param  Administrator|null  $actor  who creates the account; null when the system does
     *
     * @throws AdministratorExists when the address is taken
     * @throws Throwable
     */
    public function handle(?Administrator $actor, string $name, string $email, Role $role): NewAdministrator
    {
        $password = Str::random(self::PASSWORD_LENGTH);
        $connection = (new Administrator)->getConnection();

        try {
            $administrator = $connection->transaction(function () use ($actor, $name, $email, $role, $password): Administrator {
                // The model stores addresses in lower case; so is the search.
                if (Administrator::query()->where('email', mb_strtolower($email))->exists()) {
                    throw AdministratorExists::withEmail(mb_strtolower($email));
                }

                $administrator = new Administrator(['name' => $name, 'email' => $email, 'password' => $password]);
                $administrator->save();

                $this->events->dispatch(new AdministratorCreated($actor, $administrator));
                $this->assignRole->handle($actor, $administrator, $role);

                return $administrator;
            });
        } catch (UniqueConstraintViolationException) {
            // Two creations of one address at the same moment: the index decided.
            throw AdministratorExists::withEmail(mb_strtolower($email));
        }

        return new NewAdministrator($administrator, $password);
    }
}

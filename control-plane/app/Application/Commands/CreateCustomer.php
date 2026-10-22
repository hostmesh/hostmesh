<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Commands;

use App\Application\Events\UserCreated;
use App\Application\Exceptions\CustomerExists;
use App\Domain\Identity\Administrator;
use App\Domain\Identity\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Throwable;

final readonly class CreateCustomer
{
    /** Long enough that no rule about its content is needed. */
    public const int PASSWORD_LENGTH = 40;

    public function __construct(
        protected Dispatcher $events,
    ) {
        //
    }

    /**
     * Creates a customer's account, the login of the customer realm, with a
     * generated password (issue #134, decision 0022), and audits it in the
     * same transaction (decision 0013). Two-factor stays off: a customer
     * turns it on from the portal. Whether $actor may do this is the
     * caller's policy check, not this command's.
     *
     * @param  Administrator|null  $actor  who creates the account; null when the system does
     *
     * @throws CustomerExists when the address is taken
     * @throws Throwable
     */
    public function handle(?Administrator $actor, string $name, string $email): NewCustomer
    {
        $password = Str::random(self::PASSWORD_LENGTH);
        $connection = (new User)->getConnection();

        try {
            $user = $connection->transaction(function () use ($actor, $name, $email, $password): User {
                // The model stores addresses in lower case; so is the search.
                if (User::query()->where('email', mb_strtolower($email))->exists()) {
                    throw CustomerExists::withEmail(mb_strtolower($email));
                }

                $user = new User(['name' => $name, 'email' => $email, 'password' => $password]);
                $user->save();

                $this->events->dispatch(new UserCreated($actor, $user));

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            // Two creations of one address at the same moment: the index decided.
            throw CustomerExists::withEmail(mb_strtolower($email));
        }

        return new NewCustomer($user, $password);
    }
}

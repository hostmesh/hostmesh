<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Commands;

use App\Application\Events\PasswordReset;
use App\Application\Exceptions\AdministratorNotFound;
use App\Application\Exceptions\CustomerNotFound;
use App\Domain\Identity\Administrator;
use App\Domain\Identity\User;
use App\Support\Realms;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Str;
use Throwable;

final readonly class ResetPassword
{
    /** Long enough that no rule about its content is needed. */
    public const int PASSWORD_LENGTH = 40;

    public function __construct(
        protected Dispatcher $events,
        protected Realms $realms,
    ) {
        //
    }

    /**
     * Gives an administrator who lost the password a new, generated one.
     *
     * @return string the new password, to be shown once
     *
     * @throws AdministratorNotFound
     * @throws Throwable
     */
    public function administrator(string $email): string
    {
        $email = mb_strtolower($email);

        return $this->reset(
            fn (): Administrator => Administrator::query()->lockForUpdate()->where('email', $email)->first()
                ?? throw AdministratorNotFound::withEmail($email),
            'admin',
        );
    }

    /**
     * Gives a customer who lost the password a new, generated one.
     *
     * @return string the new password, to be shown once
     *
     * @throws CustomerNotFound
     * @throws Throwable
     */
    public function customer(string $email): string
    {
        $email = mb_strtolower($email);

        return $this->reset(
            fn (): User => User::query()->lockForUpdate()->where('email', $email)->first()
                ?? throw CustomerNotFound::withEmail($email),
            'customer',
        );
    }

    /**
     * Issue #133, decision 0023: an operator with shell access resets a
     * lost password, until an emailed link comes with the mail work of
     * Slice 6. The account's sessions end and the reset is audited, in
     * one transaction (decision 0013). Two-factor stays as it was.
     *
     * @param  callable(): (Administrator|User)  $find  the account, locked
     *
     * @throws Throwable
     */
    protected function reset(callable $find, string $realm): string
    {
        $password = Str::random(self::PASSWORD_LENGTH);
        $connection = (new Administrator)->getConnection();

        $connection->transaction(function () use ($find, $realm, $password, $connection): void {
            $account = $find();
            $account->password = $password;
            $account->save();

            $connection->table($this->realms->get($realm)->sessionTable)
                ->where('user_id', $account->id)
                ->delete();

            $this->events->dispatch(new PasswordReset($account));
        });

        return $password;
    }
}

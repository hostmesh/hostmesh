<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Commands;

use App\Application\Events\LoggedIn;
use App\Application\Events\LoginFailed;
use App\Application\Exceptions\InvalidCredentials;
use App\Application\Services\LoginAddressHasher;
use App\Application\Services\SecondFactorSession;
use App\Domain\Audit\ActorType;
use App\Domain\Identity\Administrator;
use App\Domain\Identity\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory;
use Illuminate\Contracts\Events\Dispatcher;
use SensitiveParameter;
use Throwable;

final readonly class LogIn
{
    public function __construct(
        protected Factory $auth,
        protected SecondFactorSession $secondFactor,
        protected Dispatcher $events,
        protected LoginAddressHasher $addresses,
    ) {
        //
    }

    /**
     * Logs the account in on the given session guard. The guard regenerates
     * the session ID, and its timebox makes an unknown email take as long
     * as a wrong password. Success and failure are audited (decision 0013);
     * a login whose audit event cannot be written is undone.
     *
     * @throws InvalidCredentials
     * @throws Throwable
     */
    public function handle(string $guard, string $email, #[SensitiveParameter] string $password): Authenticatable
    {
        $session = $this->auth->guard($guard);

        if (! $session instanceof SessionGuard) {
            throw new InvalidCredentials;
        }

        if (! $session->attempt(['email' => mb_strtolower($email), 'password' => $password])) {
            // The account the guard found for the address, if any.
            $attempted = $session->getLastAttempted();
            $known = $attempted instanceof Administrator || $attempted instanceof User ? $attempted : null;

            $this->events->dispatch(new LoginFailed(
                $guard === 'admin' ? ActorType::Administrator : ActorType::User,
                $known,
                $known === null ? $this->addresses->hash($email) : null,
            ));

            throw new InvalidCredentials;
        }

        // A password alone never completes the second factor (issue #25).
        $this->secondFactor->clear();

        $account = $session->user();

        if (! $account instanceof Administrator && ! $account instanceof User) {
            throw new InvalidCredentials;
        }

        try {
            $this->events->dispatch(new LoggedIn($account));
        } catch (Throwable $exception) {
            // A session is not a row: there is no transaction to roll back.
            $session->logout();

            throw $exception;
        }

        return $account;
    }
}

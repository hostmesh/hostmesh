<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Commands;

use App\Application\Events\PasswordChanged;
use App\Application\Exceptions\IncorrectPassword;
use App\Application\Exceptions\InvalidTwoFactorCode;
use App\Domain\Identity\Administrator;
use App\Domain\Identity\TwoFactor\Totp;
use App\Domain\Identity\User;
use App\Support\Realms;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Hashing\Hasher;
use SensitiveParameter;
use Throwable;

final readonly class ChangePassword
{
    public function __construct(
        protected Totp $totp,
        protected Hasher $hasher,
        protected Dispatcher $events,
        protected Realms $realms,
    ) {
        //
    }

    /**
     * An account changes its own password (issue #133, decision 0023): with
     * the current password, and a code from its authenticator whenever
     * two-factor is on, like the other account actions. The password is
     * checked first, so a wrong one does not spend the code. The account's
     * other sessions end in the same transaction as the change and its
     * audit event (decision 0013). The session that asked goes on: the
     * account object is refreshed, so the session stores the new password's
     * hash and Sanctum does not end it on the next request.
     *
     * Whether the new password follows the rule is the request's check.
     *
     * @param  string  $sessionId  the session that asked, which is kept
     *
     * @throws IncorrectPassword
     * @throws InvalidTwoFactorCode
     * @throws Throwable
     */
    public function handle(
        Administrator|User $account,
        #[SensitiveParameter] string $currentPassword,
        #[SensitiveParameter] string $newPassword,
        #[SensitiveParameter] ?string $code,
        string $sessionId,
    ): void {
        if (! $this->hasher->check($currentPassword, $account->password)) {
            throw new IncorrectPassword;
        }

        $connection = $account->getConnection();

        $changed = $connection->transaction(function () use ($account, $connection, $newPassword, $code, $sessionId): bool {
            $locked = $account->lockedCopy();

            if ($locked->hasTwoFactor() && ($code === null || ! $locked->acceptTotpCode($this->totp, $code))) {
                return false;
            }

            $locked->password = $newPassword;
            $locked->save();

            $connection->table($this->realms->get($account instanceof Administrator ? 'admin' : 'customer')->sessionTable)
                ->where('user_id', $locked->id)
                ->where('id', '!=', $sessionId)
                ->delete();

            $this->events->dispatch(new PasswordChanged($locked));

            return true;
        });

        if (! $changed) {
            throw new InvalidTwoFactorCode;
        }

        $account->refresh();
    }
}

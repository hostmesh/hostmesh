<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Commands;

use App\Application\Events\RecoveryCodeUsed;
use App\Application\Events\TwoFactorChallengeFailed;
use App\Application\Events\TwoFactorChallengePassed;
use App\Application\Exceptions\InvalidTwoFactorCode;
use App\Application\Services\SecondFactorSession;
use App\Domain\Identity\Administrator;
use App\Domain\Identity\TwoFactor\RecoveryCodes;
use App\Domain\Identity\TwoFactor\Totp;
use App\Domain\Identity\User;
use Illuminate\Contracts\Events\Dispatcher;
use SensitiveParameter;
use Throwable;

final readonly class CompleteTwoFactorChallenge
{
    public function __construct(
        protected Totp $totp,
        protected RecoveryCodes $recoveryCodes,
        protected SecondFactorSession $session,
        protected Dispatcher $events,
    ) {
        //
    }

    /**
     * Completes the second factor for the current session with a TOTP code
     * or, when $code is null, with a recovery code, which is then spent.
     * Both outcomes are audited inside the transaction (decision 0013): a
     * refusal commits its event, and a failed write spends nothing.
     *
     * @throws InvalidTwoFactorCode
     * @throws Throwable
     */
    public function handle(
        Administrator|User $account,
        #[SensitiveParameter] ?string $code,
        #[SensitiveParameter] ?string $recoveryCode,
    ): void {
        $accepted = $account->getConnection()->transaction(function () use ($account, $code, $recoveryCode): bool {
            $locked = $account->lockedCopy();

            $accepted = $locked->hasTwoFactor() && ($code !== null
                    ? $locked->acceptTotpCode($this->totp, $code)
                    : $recoveryCode !== null && $locked->consumeRecoveryCode($this->recoveryCodes->hash($recoveryCode)));

            $this->events->dispatch(match (true) {
                ! $accepted => new TwoFactorChallengeFailed(
                    $locked,
                    $code !== null ? TwoFactorChallengeFailed::TOTP : TwoFactorChallengeFailed::RECOVERY_CODE,
                ),
                $code !== null => new TwoFactorChallengePassed($locked),
                default => new RecoveryCodeUsed($locked, count($locked->two_factor_recovery_codes ?? [])),
            });

            return $accepted;
        });

        if (! $accepted) {
            throw new InvalidTwoFactorCode;
        }

        $this->session->mark($account);
    }
}

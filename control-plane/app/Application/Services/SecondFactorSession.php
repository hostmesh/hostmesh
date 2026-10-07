<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Services;

use App\Domain\Identity\Administrator;
use App\Domain\Identity\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Session\SessionManager;
use Illuminate\Session\Store;
use LogicException;

/**
 * The mark a session carries once its account has completed the second
 * factor (issue #25). It holds the account ID, so it is worth nothing to
 * another account in the same session.
 */
final readonly class SecondFactorSession
{
    protected const string KEY = 'two_factor.passed_for';

    public function __construct(
        protected SessionManager $sessions,
    ) {
        //
    }

    /** Marks the session and gives it a new ID. */
    public function mark(Authenticatable $account): void
    {
        $store = $this->store();

        $store->put(self::KEY, $account->getAuthIdentifier());
        $store->regenerate(true);
    }

    public function passedBy(Authenticatable $account): bool
    {
        return $this->store()->get(self::KEY) === $account->getAuthIdentifier();
    }

    /**
     * The step this session still owes, or null when it is complete.
     * $required: the realm makes two-factor mandatory.
     */
    public function pending(Administrator|User $account, bool $required): ?SecondFactorStep
    {
        if ($account->hasTwoFactor()) {
            return $this->passedBy($account) ? null : SecondFactorStep::Challenge;
        }

        return $required ? SecondFactorStep::Enrollment : null;
    }

    public function clear(): void
    {
        $this->store()->forget(self::KEY);
    }

    /**
     * Resolved on every call: UseSessionRealm replaces the session driver
     * for each request.
     */
    protected function store(): Store
    {
        $store = $this->sessions->driver();

        return $store instanceof Store ? $store : throw new LogicException('The session store is not available.');
    }
}

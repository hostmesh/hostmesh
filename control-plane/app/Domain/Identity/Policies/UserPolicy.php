<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Domain\Identity\Policies;

use App\Domain\Authorization\Permission;
use App\Domain\Identity\Administrator;
use App\Domain\Identity\User;

/**
 * Who may do what with a customer's user (decision 0012). An administrator
 * is checked against a permission, a user against ownership: a user owns
 * their own account and nothing of anybody else.
 */
final class UserPolicy
{
    public function view(Administrator|User $actor, User $user): bool
    {
        return $actor instanceof Administrator
            ? $actor->hasPermission(Permission::UserView)
            : $actor->is($user);
    }

    public function update(Administrator|User $actor, User $user): bool
    {
        return $actor instanceof Administrator
            ? $actor->hasPermission(Permission::UserUpdate)
            : $actor->is($user);
    }

    /** Enroll, confirm, disable, new recovery codes: only for one's own account. */
    public function manageTwoFactor(Administrator|User $actor, User $user): bool
    {
        return $actor instanceof User && $actor->is($user);
    }

    /** One's own password (decision 0023). */
    public function changePassword(Administrator|User $actor, User $user): bool
    {
        return $actor instanceof User && $actor->is($user);
    }
}

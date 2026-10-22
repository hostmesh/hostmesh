<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Domain\Identity\Policies;

use App\Domain\Authorization\Permission;
use App\Domain\Identity\Administrator;
use App\Domain\Identity\User;

/**
 * Who may do what with an administrator (decision 0012). Administrators
 * exist in the admin realm only: a customer's user is refused everything.
 */
final class AdministratorPolicy
{
    public function view(Administrator|User $actor, Administrator $administrator): bool
    {
        return $actor instanceof Administrator
            && ($actor->is($administrator) || $actor->hasPermission(Permission::AdministratorView));
    }

    public function update(Administrator|User $actor, Administrator $administrator): bool
    {
        return $actor instanceof Administrator
            && $actor->hasPermission(Permission::AdministratorUpdate);
    }

    /** Enroll, confirm, disable, new recovery codes: only for one's own account, whatever the role. */
    public function manageTwoFactor(Administrator|User $actor, Administrator $administrator): bool
    {
        return $actor instanceof Administrator && $actor->is($administrator);
    }

    /** One's own password, whatever the role (decision 0023). */
    public function changePassword(Administrator|User $actor, Administrator $administrator): bool
    {
        return $actor instanceof Administrator && $actor->is($administrator);
    }
}

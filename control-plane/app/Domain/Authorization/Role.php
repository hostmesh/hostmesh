<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Domain\Authorization;

/**
 * A named set of permissions for administrators (decision 0012). Roles are
 * defined here, in code; which administrator holds which role is data
 * (`administrator_roles`). Customers have no roles: their rules are
 * ownership rules in the policies.
 */
enum Role: string
{
    /** May do everything, including managing administrators. */
    case Owner = 'owner';

    /** May view everything and change nothing. */
    case ReadOnly = 'read-only';

    /** @return list<Permission> */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => Permission::cases(),
            self::ReadOnly => array_values(array_filter(
                Permission::cases(),
                fn (Permission $permission): bool => $permission->isReadOnly(),
            )),
        };
    }

    public function grants(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }
}

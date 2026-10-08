<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Commands;

use App\Application\Events\AdministratorRoleRevoked;
use App\Domain\Authorization\Role;
use App\Domain\Identity\Administrator;
use Illuminate\Contracts\Events\Dispatcher;
use Throwable;

final readonly class RevokeAdministratorRole
{
    public function __construct(
        protected Dispatcher $events,
    ) {
        //
    }

    /**
     * Takes the role from the administrator (decision 0012) and audits it
     * in the same transaction (decision 0013). Whether $actor may do this
     * is the caller's policy check, not this command's.
     *
     * @param  Administrator|null  $actor  who made the change; null when the system did
     * @return bool false when the administrator did not hold the role: nothing changed, nothing is audited
     *
     * @throws Throwable
     */
    public function handle(?Administrator $actor, Administrator $administrator, Role $role): bool
    {
        $connection = $administrator->getConnection();

        $revoked = $connection->transaction(function () use ($connection, $actor, $administrator, $role): bool {
            $deleted = $connection->table('administrator_roles')
                ->where('administrator_id', $administrator->id)
                ->where('role', $role->value)
                ->delete();

            if ($deleted === 0) {
                return false;
            }

            $this->events->dispatch(new AdministratorRoleRevoked($actor, $administrator, $role));

            return true;
        });

        // The instance may have read its roles before this call.
        $administrator->unsetRelation('roleAssignments');

        return $revoked;
    }
}

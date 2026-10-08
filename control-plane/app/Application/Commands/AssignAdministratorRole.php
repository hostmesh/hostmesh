<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Commands;

use App\Application\Events\AdministratorRoleAssigned;
use App\Domain\Authorization\Role;
use App\Domain\Identity\Administrator;
use Illuminate\Contracts\Events\Dispatcher;
use Throwable;

final readonly class AssignAdministratorRole
{
    public function __construct(
        protected Dispatcher $events,
    ) {
        //
    }

    /**
     * Gives the administrator the role (decision 0012) and audits it in the
     * same transaction (decision 0013). Whether $actor may do this is the
     * caller's policy check, not this command's.
     *
     * @param  Administrator|null  $actor  who made the change; null when the system did
     * @return bool false when the administrator already held the role: nothing changed, nothing is audited
     *
     * @throws Throwable
     */
    public function handle(?Administrator $actor, Administrator $administrator, Role $role): bool
    {
        $connection = $administrator->getConnection();

        $assigned = $connection->transaction(function () use ($connection, $actor, $administrator, $role): bool {
            $inserted = $connection->table('administrator_roles')->insertOrIgnore([
                'administrator_id' => $administrator->id,
                'role' => $role->value,
            ]);

            if ($inserted === 0) {
                return false;
            }

            $this->events->dispatch(new AdministratorRoleAssigned($actor, $administrator, $role));

            return true;
        });

        // The instance may have read its roles before this call.
        $administrator->unsetRelation('roleAssignments');

        return $assigned;
    }
}

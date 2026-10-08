<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Domain\Audit\Policies;

use App\Domain\Audit\AuditEventRecord;
use App\Domain\Authorization\Permission;
use App\Domain\Identity\Administrator;
use App\Domain\Identity\User;

/**
 * Who may read the audit log (decisions 0012 and 0013): an administrator
 * with `audit-event.view`, and nobody in the customer realm. There is no
 * method for changing or deleting an event, so the Gate denies both.
 */
final class AuditEventPolicy
{
    public function viewAny(Administrator|User $actor): bool
    {
        return $actor instanceof Administrator && $actor->hasPermission(Permission::AuditEventView);
    }

    public function view(Administrator|User $actor, AuditEventRecord $event): bool
    {
        return $this->viewAny($actor);
    }
}

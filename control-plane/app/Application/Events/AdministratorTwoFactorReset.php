<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Events;

use App\Domain\Audit\ActorType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEvent;
use App\Domain\Identity\Administrator;
use Carbon\CarbonImmutable;

/** An operator removed the second factor of an administrator and ended its sessions. */
final readonly class AdministratorTwoFactorReset implements Audited
{
    public function __construct(
        public Administrator $administrator,
    ) {
        //
    }

    public function toAuditEvent(): AuditEvent
    {
        return new AuditEvent(
            ActorType::System,
            null,
            AuditAction::AdministratorTwoFactorReset,
            ActorType::Administrator->value,
            $this->administrator->id,
            [],
            CarbonImmutable::now(),
        );
    }
}

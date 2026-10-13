<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Events;

use App\Domain\Audit\ActorType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEvent;
use App\Domain\Identity\Administrator;
use Carbon\CarbonImmutable;

final readonly class AdministratorCreated implements Audited
{
    /** @param  Administrator|null  $actor  who created the account; null when the system did */
    public function __construct(
        public ?Administrator $actor,
        public Administrator $administrator,
    ) {
        //
    }

    public function toAuditEvent(): AuditEvent
    {
        return new AuditEvent(
            $this->actor === null ? ActorType::System : ActorType::Administrator,
            $this->actor?->id,
            AuditAction::AdministratorCreated,
            ActorType::Administrator->value,
            $this->administrator->id,
            [],
            CarbonImmutable::now(),
        );
    }
}

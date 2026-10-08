<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Application\Events\Audited;
use App\Domain\Audit\ActorType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEvent;
use Carbon\CarbonImmutable;

final readonly class SomethingAudited implements Audited
{
    public function toAuditEvent(): AuditEvent
    {
        return new AuditEvent(
            ActorType::System,
            null,
            AuditAction::AdministratorRoleAssigned,
            'administrator',
            '0199c1d2-0000-7000-8000-000000000001',
            ['role' => 'owner'],
            CarbonImmutable::now(),
        );
    }
}

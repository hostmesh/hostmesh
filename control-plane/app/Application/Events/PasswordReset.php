<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Events;

use App\Domain\Audit\ActorType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEvent;
use App\Domain\Identity\Administrator;
use App\Domain\Identity\User;
use Carbon\CarbonImmutable;

/** An operator gave an account a new password and ended its sessions. */
final readonly class PasswordReset implements Audited
{
    public function __construct(
        public Administrator|User $account,
    ) {
        //
    }

    public function toAuditEvent(): AuditEvent
    {
        $type = ActorType::of($this->account);

        return new AuditEvent(
            ActorType::System,
            null,
            $type === ActorType::Administrator ? AuditAction::AdministratorPasswordReset : AuditAction::UserPasswordReset,
            $type->value,
            $this->account->id,
            [],
            CarbonImmutable::now(),
        );
    }
}

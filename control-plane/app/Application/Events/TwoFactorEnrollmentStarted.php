<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Events;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEvent;
use App\Domain\Identity\Administrator;
use App\Domain\Identity\User;

/** A new, unconfirmed secret was stored. The event never carries it. */
final readonly class TwoFactorEnrollmentStarted implements Audited
{
    public function __construct(
        public Administrator|User $account,
    ) {
        //
    }

    public function toAuditEvent(): AuditEvent
    {
        return AuditEvent::onOwnAccount($this->account, AuditAction::TwoFactorEnrollmentStarted);
    }
}

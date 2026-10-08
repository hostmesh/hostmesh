<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Events;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEvent;
use App\Domain\Identity\Administrator;
use App\Domain\Identity\User;

/** Every recovery code was replaced. The event carries none of them. */
final readonly class RecoveryCodesRegenerated implements Audited
{
    public function __construct(
        public Administrator|User $account,
    ) {
        //
    }

    public function toAuditEvent(): AuditEvent
    {
        return AuditEvent::onOwnAccount($this->account, AuditAction::RecoveryCodesRegenerated);
    }
}

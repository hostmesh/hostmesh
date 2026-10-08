<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Events;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEvent;
use App\Domain\Identity\Administrator;
use App\Domain\Identity\User;

/** The second step of a login was completed with a recovery code, which is now spent. */
final readonly class RecoveryCodeUsed implements Audited
{
    /** @param  int  $remaining  how many recovery codes the account has left */
    public function __construct(
        public Administrator|User $account,
        public int $remaining,
    ) {
        //
    }

    public function toAuditEvent(): AuditEvent
    {
        return AuditEvent::onOwnAccount($this->account, AuditAction::RecoveryCodeUsed, ['remaining' => $this->remaining]);
    }
}

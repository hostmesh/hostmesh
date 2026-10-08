<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Events;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEvent;
use App\Domain\Identity\Administrator;
use App\Domain\Identity\User;

/** The second step of a login was refused. The event never carries what was sent. */
final readonly class TwoFactorChallengeFailed implements Audited
{
    public const string TOTP = 'totp';

    public const string RECOVERY_CODE = 'recovery_code';

    /** @param  self::TOTP|self::RECOVERY_CODE  $method */
    public function __construct(
        public Administrator|User $account,
        public string $method,
    ) {
        //
    }

    public function toAuditEvent(): AuditEvent
    {
        return AuditEvent::onOwnAccount($this->account, AuditAction::TwoFactorChallengeFailed, ['method' => $this->method]);
    }
}

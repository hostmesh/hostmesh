<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Events;

use App\Domain\Audit\AuditEvent;

/**
 * An application event that belongs in the audit log (decision 0013).
 * Dispatch it inside the transaction that makes the change:
 * App\Application\Listeners\RecordAuditEvent writes it at once, and an
 * exception from the write must roll the change back.
 */
interface Audited
{
    /** Identifiers only. Never a name, an email address or a secret. */
    public function toAuditEvent(): AuditEvent;
}

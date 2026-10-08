<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Listeners;

use App\Application\Events\Audited;
use App\Application\Services\AuditContext;
use App\Domain\Audit\AuditLog;

/**
 * Writes every Audited event to the audit log, with where the request
 * came from. It runs inside the dispatching command's transaction and must
 * stay synchronous: on a queue, the event and the change would no longer
 * commit together (decision 0013). tests/Arch/AuditTest.php keeps it so.
 */
final readonly class RecordAuditEvent
{
    public function __construct(
        protected AuditLog $log,
        protected AuditContext $context,
    ) {
        //
    }

    public function handle(Audited $event): void
    {
        $this->log->record($event->toAuditEvent()->from(
            $this->context->ipAddress(),
            $this->context->userAgent(),
            $this->context->requestId(),
        ));
    }
}

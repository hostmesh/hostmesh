<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Domain\Audit;

/**
 * The audit log (decision 0013). Written inside the transaction that makes
 * the change: when record() throws, the caller's change must not happen.
 */
interface AuditLog
{
    public function record(AuditEvent $event): void;
}

<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Domain\Provisioning;

/**
 * What a row of `job_history` attempted. A short stable name, stored in
 * rows that are kept: never rename one, and never store a class name.
 */
enum JobType: string
{
    /** Spec section 8: an administrator asks for a failed resource to be reconciled again. */
    case RetryReconcile = 'retry_reconcile';

    /** Spec section 21: deletes audit events older than their retention. */
    case PruneAuditEvents = 'prune_audit_events';

    /** Spec section 21: clears `ip_address` and `user_agent` of older audit events. */
    case ClearAuditAddresses = 'clear_audit_addresses';

    /** Issue #155: gives a new job to unfinished rows whose job was probably lost. */
    case SweepJobHistory = 'sweep_job_history';
}

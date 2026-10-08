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
}

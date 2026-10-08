<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Jobs;

use App\Domain\Provisioning\JobHistoryEntry;
use App\Domain\Provisioning\JobType;

/**
 * Spec section 8: an administrator asks for a failed resource to be
 * reconciled again without changing its desired state.
 */
final class RetryReconcile extends OneShotJob
{
    public static function type(): JobType
    {
        return JobType::RetryReconcile;
    }

    protected function perform(JobHistoryEntry $entry): void
    {
        // Nothing to do yet. No managed resource exists before Slice 1,
        // so there is no failed status to clear. The history row records
        // that the retry was asked for and when it ran.
    }
}

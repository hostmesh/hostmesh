<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Jobs;

use App\Application\Services\JobHistorySweeper;
use App\Domain\Provisioning\JobHistoryEntry;
use App\Domain\Provisioning\JobType;

/**
 * Issue #155: gives a new job to the unfinished rows of `job_history`
 * whose job Redis probably lost. Scheduled every five minutes
 * (bootstrap/app.php). Rows beyond one batch wait for the next run.
 */
final class SweepJobHistory extends OneShotJob
{
    public static function type(): JobType
    {
        return JobType::SweepJobHistory;
    }

    protected function perform(JobHistoryEntry $entry): void
    {
        app(JobHistorySweeper::class)->sweep();
    }
}

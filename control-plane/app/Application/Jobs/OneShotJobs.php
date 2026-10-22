<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Jobs;

use App\Domain\Provisioning\JobType;

/**
 * The job that runs each type of `job_history` row. The sweep needs it to
 * push a new job for a row (issue #155). A new type without a job here
 * fails static analysis: the match must cover every case.
 */
final class OneShotJobs
{
    /**
     * @return class-string<OneShotJob>
     */
    public static function of(JobType $type): string
    {
        return match ($type) {
            JobType::RetryReconcile => RetryReconcile::class,
            JobType::PruneAuditEvents => PruneAuditEvents::class,
            JobType::ClearAuditAddresses => ClearAuditAddresses::class,
            JobType::SweepJobHistory => SweepJobHistory::class,
        };
    }
}

<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Jobs;

use App\Application\Commands\DispatchOneShotJob;
use App\Application\Services\AuditRetention;
use App\Domain\Audit\RetentionPolicy;
use App\Domain\Provisioning\JobHistoryEntry;
use App\Domain\Provisioning\JobType;

/**
 * Spec section 21: deletes audit events older than their retention, never
 * younger than the floor. Scheduled daily (bootstrap/app.php).
 * A run that is out of time hands the rest to a new run, with a row of
 * its own, until nothing is left (issue #238).
 */
final class PruneAuditEvents extends OneShotJob
{
    public static function type(): JobType
    {
        return JobType::PruneAuditEvents;
    }

    protected function perform(JobHistoryEntry $entry): void
    {
        if (! app(AuditRetention::class)->deleteExpiredEvents()) {
            app(DispatchOneShotJob::class)->handle(self::class, RetentionPolicy::RESOURCE_TYPE, RetentionPolicy::RESOURCE_ID);
        }
    }
}

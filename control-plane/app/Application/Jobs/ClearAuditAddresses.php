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
 * Spec section 21: clears `ip_address` and `user_agent` of audit events
 * older than their retention. Scheduled daily (bootstrap/app.php).
 * A run that is out of time hands the rest to a new run, with a row of
 * its own, until nothing is left (issue #238).
 */
final class ClearAuditAddresses extends OneShotJob
{
    public static function type(): JobType
    {
        return JobType::ClearAuditAddresses;
    }

    protected function perform(JobHistoryEntry $entry): void
    {
        if (! app(AuditRetention::class)->clearExpiredAddresses()) {
            app(DispatchOneShotJob::class)->handle(self::class, RetentionPolicy::RESOURCE_TYPE, RetentionPolicy::RESOURCE_ID);
        }
    }
}

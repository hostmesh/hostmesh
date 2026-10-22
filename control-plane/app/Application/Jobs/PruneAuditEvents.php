<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Jobs;

use App\Application\Services\AuditRetention;
use App\Domain\Provisioning\JobHistoryEntry;
use App\Domain\Provisioning\JobType;

/**
 * Spec section 21: deletes audit events older than their retention, never
 * younger than the floor. Scheduled daily (bootstrap/app.php).
 */
final class PruneAuditEvents extends OneShotJob
{
    public static function type(): JobType
    {
        return JobType::PruneAuditEvents;
    }

    protected function perform(JobHistoryEntry $entry): void
    {
        app(AuditRetention::class)->deleteExpiredEvents();
    }
}

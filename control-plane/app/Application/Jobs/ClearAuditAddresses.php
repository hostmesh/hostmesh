<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Jobs;

use App\Application\Services\AuditRetention;
use App\Domain\Provisioning\JobHistoryEntry;
use App\Domain\Provisioning\JobType;

/**
 * Spec section 21: clears `ip_address` and `user_agent` of audit events
 * older than their retention. Scheduled daily (bootstrap/app.php).
 */
final class ClearAuditAddresses extends OneShotJob
{
    public static function type(): JobType
    {
        return JobType::ClearAuditAddresses;
    }

    protected function perform(JobHistoryEntry $entry): void
    {
        app(AuditRetention::class)->clearExpiredAddresses();
    }
}

<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Services;

use App\Domain\Audit\RetentionPolicy;
use Illuminate\Database\ConnectionInterface;

/**
 * Applies the retention of the audit log (spec section 21, decision 0013).
 * The cutoffs are PostgreSQL's now(), the clock the trigger on
 * `audit_events` checks the floor against. Each step is one statement and
 * running it again changes nothing.
 */
final readonly class AuditRetention
{
    public function __construct(
        protected ConnectionInterface $connection,
        protected RetentionPolicy $policy,
    ) {
        //
    }

    /**
     * @return int the events deleted
     */
    public function deleteExpiredEvents(): int
    {
        return $this->connection->delete(
            'DELETE FROM audit_events WHERE created_at < now() - make_interval(months => ?)',
            [$this->policy->eventMonths],
        );
    }

    /**
     * Clears `ip_address` and `user_agent` together.
     *
     * @return int the events changed
     */
    public function clearExpiredAddresses(): int
    {
        return $this->connection->update(
            'UPDATE audit_events SET ip_address = NULL, user_agent = NULL
                WHERE created_at < now() - make_interval(days => ?)
                AND (ip_address IS NOT NULL OR user_agent IS NOT NULL)',
            [$this->policy->addressDays],
        );
    }
}

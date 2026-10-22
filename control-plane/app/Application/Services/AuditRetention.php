<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Services;

use App\Domain\Audit\RetentionPolicy;
use Illuminate\Database\ConnectionInterface;

/**
 * Applies the retention of the audit log (spec section 21, decision 0013).
 *
 * The work goes in batches, oldest first, each one statement and its own
 * transaction, so a long backlog never holds its rows for long (issue
 * #238). A run takes its cutoff from PostgreSQL's now() once, at the
 * start: a later batch only finds older events than the trigger on
 * `audit_events` requires. Running a step again, or after it was
 * interrupted, changes nothing beyond the retention.
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
     * @return bool true when no expired event is left, false when the run's time was up first
     */
    public function deleteExpiredEvents(): bool
    {
        $cutoff = $this->connection->scalar('SELECT now() - make_interval(months => ?)', [$this->policy->eventMonths]);

        return $this->inBatches(fn (): int => $this->connection->delete(
            'DELETE FROM audit_events WHERE id IN (
                SELECT id FROM audit_events WHERE created_at < ? ORDER BY created_at LIMIT ?
            )',
            [$cutoff, $this->policy->batchSize],
        ));
    }

    /**
     * Clears `ip_address` and `user_agent` together. The condition matches
     * the partial index `audit_events_addresses_created_at_index`, so a
     * batch never reads past the events cleared before it.
     *
     * @return bool true when no expired address is left, false when the run's time was up first
     */
    public function clearExpiredAddresses(): bool
    {
        $cutoff = $this->connection->scalar('SELECT now() - make_interval(days => ?)', [$this->policy->addressDays]);

        return $this->inBatches(fn (): int => $this->connection->update(
            'UPDATE audit_events SET ip_address = NULL, user_agent = NULL WHERE id IN (
                SELECT id FROM audit_events
                WHERE created_at < ? AND (ip_address IS NOT NULL OR user_agent IS NOT NULL)
                ORDER BY created_at LIMIT ?
            )',
            [$cutoff, $this->policy->batchSize],
        ));
    }

    /**
     * Runs batches until one comes back short or the time is up. A batch
     * that has begun always finishes.
     *
     * @param  callable(): int  $batch  changes up to one batch of events and returns how many
     */
    private function inBatches(callable $batch): bool
    {
        // Monotonic: a clock that is set back cannot stretch a run.
        $deadline = hrtime(true) + $this->policy->runSeconds * 1_000_000_000;

        do {
            if ($batch() < $this->policy->batchSize) {
                return true;
            }
        } while (hrtime(true) < $deadline);

        return false;
    }
}

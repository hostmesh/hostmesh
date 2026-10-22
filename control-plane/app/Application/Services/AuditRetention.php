<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\Events\AuditAddressesCleared;
use App\Application\Events\Audited;
use App\Application\Events\AuditEventsDeleted;
use App\Domain\Audit\RetentionPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Throwable;
use UnexpectedValueException;

/**
 * Applies the retention of the audit log (spec section 21, decision 0013).
 *
 * The work goes in batches, oldest first, each in its own transaction, so
 * a long backlog never holds its rows for long (issue #238). A batch that
 * changed anything records an audit event about it in the same
 * transaction: the log explains its own gaps, and when the event cannot
 * be written, the batch does not happen (issue #239). A run takes its
 * cutoff from PostgreSQL's now() once, at the start: a later batch only
 * finds older events than the trigger on `audit_events` requires.
 * Running a step again, or after it was interrupted, changes nothing
 * beyond the retention.
 */
final readonly class AuditRetention
{
    public function __construct(
        protected ConnectionInterface $connection,
        protected Dispatcher $events,
        protected RetentionPolicy $policy,
    ) {
        //
    }

    /**
     * @return bool true when no expired event is left, false when the run's time was up first
     *
     * @throws Throwable when a batch or its audit event cannot be written; that batch is rolled back
     */
    public function deleteExpiredEvents(): bool
    {
        $cutoff = $this->cutoff('make_interval(months => ?)', $this->policy->eventMonths);
        $at = CarbonImmutable::parse($cutoff);

        return $this->inBatches(
            fn (): int => $this->connection->delete(
                'DELETE FROM audit_events WHERE id IN (
                    SELECT id FROM audit_events WHERE created_at < ? ORDER BY created_at LIMIT ?
                )',
                [$cutoff, $this->policy->batchSize],
            ),
            fn (int $count): Audited => new AuditEventsDeleted($count, $at),
        );
    }

    /**
     * Clears `ip_address` and `user_agent` together. The condition matches
     * the partial index `audit_events_addresses_created_at_index`, so a
     * batch never reads past the events cleared before it.
     *
     * @return bool true when no expired address is left, false when the run's time was up first
     *
     * @throws Throwable when a batch or its audit event cannot be written; that batch is rolled back
     */
    public function clearExpiredAddresses(): bool
    {
        $cutoff = $this->cutoff('make_interval(days => ?)', $this->policy->addressDays);
        $at = CarbonImmutable::parse($cutoff);

        return $this->inBatches(
            fn (): int => $this->connection->update(
                'UPDATE audit_events SET ip_address = NULL, user_agent = NULL WHERE id IN (
                    SELECT id FROM audit_events
                    WHERE created_at < ? AND (ip_address IS NOT NULL OR user_agent IS NOT NULL)
                    ORDER BY created_at LIMIT ?
                )',
                [$cutoff, $this->policy->batchSize],
            ),
            fn (int $count): Audited => new AuditAddressesCleared($count, $at),
        );
    }

    /**
     * PostgreSQL's now() less the given interval, as PostgreSQL writes it.
     * Bound as this string, the cutoff keeps its microseconds and its zone.
     */
    private function cutoff(string $interval, int $amount): string
    {
        $cutoff = $this->connection->scalar("SELECT now() - $interval", [$amount]);

        return is_string($cutoff) ? $cutoff : throw new UnexpectedValueException('PostgreSQL returned no cutoff.');
    }

    /**
     * Runs batches until one comes back short or the time is up. A batch
     * that has begun always finishes.
     *
     * @param  callable(): int  $batch  changes up to one batch of events and returns how many
     * @param  callable(int): Audited  $audited  the audit event of a batch that changed this many
     *
     * @throws Throwable
     */
    private function inBatches(callable $batch, callable $audited): bool
    {
        // Monotonic: a clock that is set back cannot stretch a run.
        $deadline = hrtime(true) + $this->policy->runSeconds * 1_000_000_000;

        do {
            $count = $this->connection->transaction(function () use ($batch, $audited): int {
                $count = $batch();

                if ($count > 0) {
                    $this->events->dispatch($audited($count));
                }

                return $count;
            });

            if ($count < $this->policy->batchSize) {
                return true;
            }
        } while (hrtime(true) < $deadline);

        return false;
    }
}

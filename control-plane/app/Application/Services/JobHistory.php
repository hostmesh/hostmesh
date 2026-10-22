<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\Exceptions\JobStillRunning;
use App\Domain\Provisioning\FailureClass;
use App\Domain\Provisioning\JobHistoryEntry;
use App\Domain\Provisioning\JobStatus;
use App\Domain\Provisioning\JobType;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Throwable;

/**
 * Writes `job_history` (spec section 6, decision 0014). The row is the
 * authority for a job: it counts the attempts and holds the result. A
 * message is stored as given, so it must never contain a secret.
 */
final readonly class JobHistory
{
    public const string INTERRUPTED = 'The previous run did not finish.';

    public const int MESSAGE_LIMIT = 1000;

    public function __construct(
        protected ConnectionInterface $connection,
    ) {
        //
    }

    /**
     * Records that an operation was asked for. Call it inside the
     * transaction that asks, and push the job after commit.
     */
    public function open(
        JobType $type,
        string $resourceType,
        string $resourceId,
        ?int $generation = null,
        ?string $nodeId = null,
    ): JobHistoryEntry {
        return JobHistoryEntry::query()->create([
            'type' => $type,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'dispatch_id' => (string) Str::uuid(),
            'generation' => $generation,
            'node_id' => $nodeId,
            'status' => JobStatus::Pending,
            'attempts' => 0,
        ]);
    }

    /**
     * Marks the start of a run. Null when there is nothing to run: the
     * row is unknown, already finished, or holds another dispatch token
     * because the sweep gave it a new job. A row that is still running
     * after the timeout of its run means that run died, which counts as
     * one retryable attempt. Inside the timeout the run may be alive, and
     * a second one beside it would write the same row.
     *
     * @param  string  $dispatchId  the token the job was pushed with
     * @param  int  $timeout  seconds one run of the job may take
     *
     * @throws JobStillRunning when the previous run is inside its timeout
     * @throws Throwable
     */
    public function begin(string $id, string $dispatchId, int $timeout): ?JobHistoryEntry
    {
        // PostgreSQL answers a malformed UUID with an error, not with no row.
        if (! Str::isUuid($id)) {
            return null;
        }

        return $this->connection->transaction(function () use ($id, $dispatchId, $timeout): ?JobHistoryEntry {
            $entry = JobHistoryEntry::query()->lockForUpdate()->find($id);

            if ($entry === null || $entry->status->isFinished() || $entry->dispatch_id !== $dispatchId) {
                return null;
            }

            if ($entry->status === JobStatus::Running) {
                // Every write of a run goes through begin(), so the row
                // was last touched when the run started, or later, when
                // the sweep gave it a new job. Then the wait is longer.
                $elapsed = (int) $entry->updated_at->diffInSeconds(CarbonImmutable::now());

                if ($elapsed < $timeout) {
                    throw new JobStillRunning($timeout - $elapsed);
                }

                $entry->attempts++;
                $entry->failure_class = FailureClass::Retryable;
                $entry->message = self::INTERRUPTED;
            }

            $entry->status = JobStatus::Running;
            $entry->started_at ??= CarbonImmutable::now();
            // A row that changes nothing else must still say when this run began.
            $entry->updated_at = CarbonImmutable::now();
            $entry->save();

            return $entry;
        });
    }

    /**
     * Gives up to `$limit` unfinished rows that nobody touched since
     * `$before` a new dispatch token, oldest first, and returns them: each
     * needs a new job that carries its token. The new token turns any
     * copy that Redis still holds into one that does nothing, and the
     * touch keeps the row from being handed out again on the next sweep.
     * A row another sweep is handing out at the same moment is skipped.
     * Call it inside the transaction that pushes the jobs after commit.
     *
     * The condition matches the partial index
     * `job_history_unfinished_updated_at_index`.
     *
     * @return list<JobHistoryEntry>
     */
    public function redispatch(CarbonImmutable $before, int $limit): array
    {
        $rows = $this->connection->select(
            "UPDATE job_history SET dispatch_id = gen_random_uuid(), updated_at = ?
                WHERE id IN (
                    SELECT id FROM job_history
                    WHERE status IN ('pending', 'running', 'waiting', 'retrying') AND updated_at < ?
                    ORDER BY updated_at LIMIT ?
                    FOR UPDATE SKIP LOCKED
                )
                RETURNING *",
            [$this->timestamp(CarbonImmutable::now()), $this->timestamp($before), $limit],
        );

        return array_values(JobHistoryEntry::hydrate($rows)->all());
    }

    public function succeeded(JobHistoryEntry $entry): void
    {
        $entry->status = JobStatus::Succeeded;
        $entry->failure_class = null;
        $entry->message = null;
        $entry->finished_at = CarbonImmutable::now();
        $entry->save();
    }

    /**
     * Blocked on an external condition. Never counts toward the attempt
     * limit (spec section 8).
     */
    public function waiting(JobHistoryEntry $entry, string $reason): void
    {
        $entry->status = JobStatus::Waiting;
        $entry->failure_class = FailureClass::Waiting;
        $entry->message = $this->cut($reason);
        $entry->save();
    }

    /**
     * Counts a retryable failure.
     *
     * @return int the attempts made so far
     */
    public function retrying(JobHistoryEntry $entry, string $message): int
    {
        $entry->attempts++;
        $entry->status = JobStatus::Retrying;
        $entry->failure_class = FailureClass::Retryable;
        $entry->message = $this->cut($message);
        $entry->save();

        return $entry->attempts;
    }

    public function failed(JobHistoryEntry $entry, FailureClass $class, string $message): void
    {
        $entry->status = JobStatus::Failed;
        $entry->failure_class = $class;
        $entry->message = $this->cut($message);
        $entry->finished_at = CarbonImmutable::now();
        $entry->save();
    }

    /**
     * In whole seconds, as Eloquent writes the columns (PostgreSQL would
     * round a fraction up), and with the zone, which a bound date lacks.
     */
    protected function timestamp(CarbonImmutable $at): string
    {
        return $at->format('Y-m-d H:i:sP');
    }

    protected function cut(string $message): string
    {
        return mb_substr($message, 0, self::MESSAGE_LIMIT);
    }
}

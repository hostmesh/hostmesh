<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Services;

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
            'generation' => $generation,
            'node_id' => $nodeId,
            'status' => JobStatus::Pending,
            'attempts' => 0,
        ]);
    }

    /**
     * Marks the start of a run. Null when there is nothing to run: the
     * row is unknown or already finished. A row that is still running
     * means the previous run died, which counts as one retryable attempt.
     *
     * @throws Throwable
     */
    public function begin(string $id): ?JobHistoryEntry
    {
        // PostgreSQL answers a malformed UUID with an error, not with no row.
        if (! Str::isUuid($id)) {
            return null;
        }

        return $this->connection->transaction(function () use ($id): ?JobHistoryEntry {
            $entry = JobHistoryEntry::query()->lockForUpdate()->find($id);

            if ($entry === null || $entry->status->isFinished()) {
                return null;
            }

            if ($entry->status === JobStatus::Running) {
                $entry->attempts++;
                $entry->failure_class = FailureClass::Retryable;
                $entry->message = self::INTERRUPTED;
            }

            $entry->status = JobStatus::Running;
            $entry->started_at ??= CarbonImmutable::now();
            $entry->save();

            return $entry;
        });
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

    protected function cut(string $message): string
    {
        return mb_substr($message, 0, self::MESSAGE_LIMIT);
    }
}

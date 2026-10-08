<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Commands;

use App\Application\Jobs\OneShotJob;
use App\Application\Services\JobHistory;
use App\Domain\Provisioning\JobHistoryEntry;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Throwable;

final readonly class DispatchOneShotJob
{
    public function __construct(
        protected ConnectionInterface $connection,
        protected JobHistory $history,
        protected Dispatcher $bus,
    ) {
        //
    }

    /**
     * Asks for a one-shot job (spec section 6). The history row is written
     * in a transaction and the job is pushed after it commits, so a job
     * never runs without its row and a rolled-back request pushes nothing.
     * If Redis loses the push, the row stays `pending` and shows it.
     *
     * Whether the caller may ask is the caller's policy check, not this
     * command's.
     *
     * @param  class-string<OneShotJob>  $job
     * @param  string  $resourceType  a short stable name such as `website`, never a class name
     * @param  int|null  $generation  the generation the job acts on, when it acts on one
     * @param  string|null  $nodeId  the node the job runs for, when there is one
     *
     * @throws Throwable
     */
    public function handle(
        string $job,
        string $resourceType,
        string $resourceId,
        ?int $generation = null,
        ?string $nodeId = null,
    ): JobHistoryEntry {
        return $this->connection->transaction(function () use ($job, $resourceType, $resourceId, $generation, $nodeId): JobHistoryEntry {
            $entry = $this->history->open($job::type(), $resourceType, $resourceId, $generation, $nodeId);

            $this->bus->dispatch((new $job($entry->id))->afterCommit());

            return $entry;
        });
    }
}

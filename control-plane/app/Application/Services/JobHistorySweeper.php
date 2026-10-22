<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\Jobs\OneShotJobs;
use App\Domain\Provisioning\SweepPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Throwable;

/**
 * Repairs what Redis lost (issue #155, decision 0014). Redis must never be
 * needed for correctness, yet a row whose job it lost stays unfinished for
 * good. The application never reads the queue, so the sweep cannot see
 * whether a job is there. It goes by age: an unfinished row that nobody
 * touched for longer than any delay gets a new token and a new job.
 *
 * The row is never failed here. A row that was running counts the lost
 * run as an attempt when its new job starts, so the attempt limit still
 * ends it.
 */
final readonly class JobHistorySweeper
{
    public function __construct(
        protected ConnectionInterface $connection,
        protected JobHistory $history,
        protected Dispatcher $bus,
        protected SweepPolicy $policy,
    ) {
        //
    }

    /**
     * Gives up to one batch of stale rows a new job each, pushed after
     * the new tokens commit.
     *
     * @return int how many rows got a new job
     *
     * @throws Throwable
     */
    public function sweep(): int
    {
        return $this->connection->transaction(function (): int {
            $before = CarbonImmutable::now()->subSeconds($this->policy->staleAfter);
            $entries = $this->history->redispatch($before, SweepPolicy::BATCH_SIZE);

            foreach ($entries as $entry) {
                $job = OneShotJobs::of($entry->type);

                $this->bus->dispatch((new $job($entry->id, $entry->dispatch_id))->afterCommit());
            }

            return count($entries);
        });
    }
}

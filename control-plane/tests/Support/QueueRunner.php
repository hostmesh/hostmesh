<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Tests\Support;

use App\Application\Jobs\OneShotJob;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * A queue of its own on the real Redis connection (database 15), and a
 * worker that runs in the test's process. Tests run in parallel and share
 * Redis, so every runner has a queue name nobody else uses. A delayed job
 * follows the test clock: `$this->travel()` makes it due.
 */
final class QueueRunner
{
    public readonly string $queue;

    public function __construct()
    {
        $this->queue = 'test-'.Str::uuid();
    }

    public function push(OneShotJob $job): void
    {
        Queue::connection('redis')->push($job, '', $this->queue);
    }

    /**
     * Runs the next job that is due, if there is one, with the options of
     * a plain `queue:work`: one try unless the job says otherwise.
     */
    public function runNext(): void
    {
        /** @var Worker $worker */
        $worker = app('queue.worker');

        $worker->runNextJob('redis', $this->queue, new WorkerOptions(maxTries: 1, sleep: 0));
    }

    /** Jobs on the queue: ready, delayed and reserved. */
    public function size(): int
    {
        return Queue::connection('redis')->size($this->queue);
    }

    public function flush(): void
    {
        Redis::connection()->del(
            "queues:$this->queue",
            "queues:$this->queue:delayed",
            "queues:$this->queue:reserved",
            "queues:$this->queue:notify",
        );
    }
}

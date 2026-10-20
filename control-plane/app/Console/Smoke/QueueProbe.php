<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Smoke;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * The job of `smoke:queue` (issue #156). It marks in the cache that a
 * worker ran it, and nothing else: it is no OneShotJob, so it writes no
 * row to job_history. No connection or queue of its own, so it goes where
 * every job of the application goes.
 */
final class QueueProbe implements ShouldQueue
{
    use Queueable;

    /** Long enough for the command to see it, short enough to leave nothing for long. */
    public const int MARKER_SECONDS = 60;

    public function __construct(
        public readonly string $key,
    ) {
        //
    }

    public function handle(): void
    {
        Cache::put($this->key, true, self::MARKER_SECONDS);
    }
}

<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Smoke;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * `php artisan smoke:queue` (issue #156): dispatches a job and waits until
 * a worker has run it. The smoke test of the development stack calls it,
 * so that a worker on the wrong connection or queue fails. Registered in
 * the development and test environments only (AppServiceProvider).
 */
final class QueueCommand extends Command
{
    /** Where the command exists. An allowlist: an environment nobody thought of is refused. */
    public const array ENVIRONMENTS = ['local', 'testing'];

    protected const int MAX_TIMEOUT = 300;

    protected const int POLL_MICROSECONDS = 250_000;

    protected $signature = 'smoke:queue {--timeout=15 : Seconds to wait for a worker}';

    protected $description = 'Dispatch a job and wait until a queue worker has run it';

    public function handle(): int
    {
        $timeout = filter_var($this->option('timeout'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => self::MAX_TIMEOUT],
        ]);

        if ($timeout === false) {
            $this->components->error('--timeout must be a whole number of seconds from 1 to '.self::MAX_TIMEOUT.'.');

            return self::FAILURE;
        }

        $key = 'smoke:queue:'.Str::uuid7();
        $started = microtime(true);

        try {
            QueueProbe::dispatch($key);

            while (! Cache::has($key)) {
                if (microtime(true) - $started >= $timeout) {
                    $this->components->error("The job was not consumed within $timeout s. Is a worker listening on the connection and queue the application dispatches to?");

                    return self::FAILURE;
                }

                usleep(self::POLL_MICROSECONDS);
            }
        } finally {
            Cache::forget($key);
        }

        $this->components->info(sprintf('A worker consumed the job in %.1f s.', microtime(true) - $started));

        return self::SUCCESS;
    }
}

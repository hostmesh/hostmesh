<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Application\Jobs\OneShotJob;
use App\Domain\Provisioning\JobHistoryEntry;
use App\Domain\Provisioning\JobType;
use Throwable;

/**
 * A one-shot job that does what the test wrote down: each run takes the
 * next entry of the script and throws it, or succeeds on null and when
 * the script is empty. The worker runs in the test's process, so the
 * static state is shared. Reset both in beforeEach.
 */
final class ScriptedJob extends OneShotJob
{
    /** @var list<Throwable|null> */
    public static array $script = [];

    public static int $runs = 0;

    // The only type there is. The fixture does not need its own.
    public static function type(): JobType
    {
        return JobType::RetryReconcile;
    }

    protected function perform(JobHistoryEntry $entry): void
    {
        self::$runs++;

        $outcome = array_shift(self::$script);

        if ($outcome instanceof Throwable) {
            throw $outcome;
        }
    }
}

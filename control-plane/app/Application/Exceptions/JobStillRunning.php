<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Exceptions;

use RuntimeException;

/**
 * The history row is `running` and its run is still inside the job's
 * timeout, so nobody can say that it died. The job was delivered twice or
 * pushed again by hand, and must look again later instead of running.
 */
final class JobStillRunning extends RuntimeException
{
    /**
     * @param  int  $retryIn  seconds until the run's timeout has passed
     */
    public function __construct(
        public readonly int $retryIn,
    ) {
        parent::__construct('The previous run may still be alive.');
    }
}

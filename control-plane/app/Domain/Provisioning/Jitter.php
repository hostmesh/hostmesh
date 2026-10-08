<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Domain\Provisioning;

/**
 * The random share RetryPolicy takes off a delay, so that jobs which
 * failed together do not come back together. An interface so that a test
 * can fix it.
 */
interface Jitter
{
    /** @return float from 0.0 to 1.0, both included */
    public function next(): float;
}

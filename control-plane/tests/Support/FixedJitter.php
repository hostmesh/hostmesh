<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Provisioning\Jitter;

/**
 * Jitter a test decides: 0.0 gives the full delay, 1.0 the shortest.
 */
final readonly class FixedJitter implements Jitter
{
    public function __construct(
        private float $value,
    ) {
        //
    }

    public function next(): float
    {
        return $this->value;
    }
}

<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Infrastructure\Provisioning;

use App\Domain\Provisioning\Jitter;
use Random\IntervalBoundary;
use Random\Randomizer;

final readonly class RandomJitter implements Jitter
{
    public function next(): float
    {
        return (new Randomizer)->getFloat(0.0, 1.0, IntervalBoundary::ClosedClosed);
    }
}

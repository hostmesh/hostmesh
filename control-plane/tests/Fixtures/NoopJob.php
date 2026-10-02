<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;

final class NoopJob implements ShouldQueue
{
    public function handle(): void {}
}

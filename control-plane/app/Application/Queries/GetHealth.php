<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Queries;

/**
 * Liveness: the application booted and can answer. It checks no dependency.
 */
final class GetHealth
{
    public function handle(): HealthStatus
    {
        return HealthStatus::Ok;
    }
}

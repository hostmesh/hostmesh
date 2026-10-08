<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Domain\Provisioning;

/**
 * Where a row of `job_history` stands (decision 0014). The value is
 * stored: never rename one.
 */
enum JobStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Waiting = 'waiting';
    case Retrying = 'retrying';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /**
     * A finished row never runs again. A new attempt is a new row.
     */
    public function isFinished(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }
}

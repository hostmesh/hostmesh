<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Domain\Provisioning;

use InvalidArgumentException;

/**
 * When a row of `job_history` has probably lost its job (issue #155,
 * decision 0014). Redis can lose a push or its data, and the row then
 * stays unfinished for good. An unfinished row that nobody touched for
 * `staleAfter` seconds gets a new job.
 *
 * A row that is waiting out a delay is not touched either, so the age
 * must exceed the longest delay: the retry cap (600 seconds by default)
 * and the recheck delay. A shorter age is safe, because the old copy of
 * the job does nothing once the row has a new token, but the job then
 * runs before its delay is over.
 */
final readonly class SweepPolicy
{
    /**
     * The sweep acts on the whole job history, not on one resource. Its
     * own `job_history` rows name it with this type and fixed ID, as the
     * audit log's retention jobs do (decision 0014, amendment of
     * 2026-10-22).
     */
    public const string RESOURCE_TYPE = 'job_history';

    public const string RESOURCE_ID = '00000000-0000-4000-8000-000000000003';

    /**
     * Rows one sweep gives back at most. After an outage there can be
     * many; the next sweep takes the rest.
     */
    public const int BATCH_SIZE = 1000;

    /**
     * @param  int  $staleAfter  seconds without a change, at least 1
     */
    public function __construct(
        public int $staleAfter,
    ) {
        if ($staleAfter < 1) {
            throw new InvalidArgumentException('A row must be left alone for at least 1 second before it is swept.');
        }
    }
}

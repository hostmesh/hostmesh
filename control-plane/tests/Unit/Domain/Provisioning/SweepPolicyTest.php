<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Domain\Provisioning\SweepPolicy;

// Issue #155: a row of job_history that nobody touched for a while has
// probably lost its job in Redis. The sweep gives it a new one.

it('keeps the configured age', function (): void {
    expect((new SweepPolicy(900))->staleAfter)->toBe(900);
});

it('refuses an age of less than a second', function (int $seconds): void {
    new SweepPolicy($seconds);
})->with([0, -1])->throws(InvalidArgumentException::class, 'at least 1 second');

it('names the job history with a fixed ID of its own', function (): void {
    expect(SweepPolicy::RESOURCE_TYPE)->toBe('job_history')
        ->and(SweepPolicy::RESOURCE_ID)->toBe('00000000-0000-4000-8000-000000000003');
});

it('gives back at most 1000 rows a run', function (): void {
    expect(SweepPolicy::BATCH_SIZE)->toBe(1000);
});

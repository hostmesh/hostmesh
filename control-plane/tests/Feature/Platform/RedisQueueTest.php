<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Fixtures\NoopJob;

it('round-trips a job through the redis queue connection', function (): void {
    $queue = 'test-'.Str::uuid();
    $connection = Queue::connection('redis');

    $connection->push(new NoopJob(), '', $queue);

    expect($connection->size($queue))->toBe(1);

    $job = $connection->pop($queue);

    expect($job)->not->toBeNull()
        ->and($job->resolveName())->toBe(NoopJob::class);

    $job->delete();

    expect($connection->size($queue))->toBe(0);
});

<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Dotenv\Dotenv;
use Illuminate\Support\Arr;

// Spec section 24 and non-negotiable 7: Redis carries queues, the cache
// and rate limiting. It is never the source of truth. The files are read
// directly where the test environment overrides a value (the queue is
// `sync` and the cache is `array` under test).

it('keeps sessions, failed jobs and job batches in PostgreSQL', function (): void {
    expect(config('session.driver'))->toBe('database')
        ->and(config('queue.failed.driver'))->toBe('database-uuids')
        ->and(config('queue.failed.database'))->toBe('pgsql')
        ->and(config('queue.batching.database'))->toBe('pgsql');
});

it('declares two Redis connections: one for the queue, one for the cache', function (): void {
    $database = require config_path('database.php');
    $queue = require config_path('queue.php');
    $cache = require config_path('cache.php');

    $redisQueues = array_filter($queue['connections'], fn (array $connection): bool => $connection['driver'] === 'redis');

    expect(array_keys(Arr::except($database['redis'], ['client', 'options'])))->toBe(['default', 'cache'])
        ->and(array_keys($redisQueues))->toBe(['redis'])
        ->and($redisQueues['redis']['connection'])->toBe('default')
        ->and($cache['stores']['redis']['connection'])->toBe('cache');
});

// The rate limiter has no store of its own: it counts in the cache.
it('counts rate limits in the cache', function (): void {
    expect(config('cache.limiter'))->toBeNull();
});

it('points the example environment at Redis for the queue and the cache, and at nothing else', function (): void {
    $example = Dotenv::parse((string) file_get_contents(base_path('.env.example')));

    $onRedis = array_keys(array_filter($example, fn (?string $value): bool => $value === 'redis'));

    expect($onRedis)->toEqualCanonicalizing(['QUEUE_CONNECTION', 'CACHE_STORE', 'REDIS_HOST']);
});

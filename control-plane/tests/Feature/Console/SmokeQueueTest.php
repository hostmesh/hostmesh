<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Console\Smoke\QueueProbe;
use App\Domain\Provisioning\JobHistoryEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

// Issue #156: the smoke test of the development stack dispatches a job and
// fails unless a worker consumes it. A worker that listens on the wrong
// connection or queue used to pass.

uses(RefreshDatabase::class);

it('passes when the dispatched job runs, and leaves nothing behind', function (): void {
    // The test environment's queue is `sync`: the job runs as it is dispatched.
    $this->artisan('smoke:queue')
        ->expectsOutputToContain('consumed')
        ->assertSuccessful();

    expect(JobHistoryEntry::query()->count())->toBe(0);
});

it('fails when nothing consumes the job within the timeout', function (): void {
    Queue::fake();

    $this->artisan('smoke:queue', ['--timeout' => 1])
        ->expectsOutputToContain('was not consumed within 1 s')
        ->assertFailed();

    Queue::assertPushed(QueueProbe::class);
});

it('dispatches the probe the way the application dispatches any job', function (): void {
    Queue::fake();

    $this->artisan('smoke:queue', ['--timeout' => 1]);

    // No connection or queue of its own: the defaults, as every job.
    Queue::assertPushed(QueueProbe::class, fn (QueueProbe $probe): bool => $probe->connection === null && $probe->queue === null);
});

it('removes its marker from the cache', function (): void {
    $keys = [];
    Cache::spy();
    Cache::shouldReceive('put')->andReturnUsing(function (string $key) use (&$keys): bool {
        $keys[] = $key;

        return true;
    });
    Cache::shouldReceive('has')->andReturn(true);

    $this->artisan('smoke:queue')->assertSuccessful();

    expect($keys)->toHaveCount(1);
    Cache::shouldHaveReceived('forget')->with($keys[0])->once();
});

it('refuses a timeout that is not a positive number of seconds', function (string $timeout): void {
    $this->artisan('smoke:queue', ['--timeout' => $timeout])
        ->expectsOutputToContain('--timeout must be a whole number of seconds from 1 to 300')
        ->assertFailed();
})->with(['0', '-1', 'soon', '301']);

it('exists only in the development and test environments', function (string $environment, bool $registered): void {
    $result = Process::path(base_path())->env(['APP_ENV' => $environment])->run([PHP_BINARY, 'artisan', 'list', '--raw']);

    expect($result->successful())->toBeTrue()
        ->and(str_contains($result->output(), 'smoke:queue'))->toBe($registered);
})->with([
    'local' => ['local', true],
    'testing' => ['testing', true],
    'production' => ['production', false],
    'staging' => ['staging', false],
    'e2e' => ['e2e', false],
]);

<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('uses PostgreSQL as the default connection', function (): void {
    expect(config('database.default'))->toBe('pgsql');
});

// Reads the file, not config(): Laravel merges its own default connections
// into the loaded configuration, so the runtime list is always longer.
it('declares PostgreSQL as the only connection', function (): void {
    $declared = require config_path('database.php');

    expect(array_keys($declared['connections']))->toBe(['pgsql']);
});

it('reaches a real PostgreSQL server', function (): void {
    expect(DB::scalar('select version()'))->toBeString()->toStartWith('PostgreSQL');
});

it('applies the migrations', function (): void {
    expect(Schema::hasTable('failed_jobs'))->toBeTrue()
        ->and(Schema::hasTable('job_history'))->toBeTrue();
});

// Decision 0014: queues run on Redis, so Laravel's database-queue table
// does not exist. The history of jobs is `job_history`.
it('has no database queue table', function (): void {
    expect(Schema::hasTable('jobs'))->toBeFalse();
});

// A database migrated before decision 0014 still has the table. The
// migration that creates `job_history` removes it, so old and new
// databases end up the same.
it('removes the database queue table from an older database', function (): void {
    Schema::drop('job_history');
    Schema::create('jobs', function (Blueprint $table): void {
        $table->id();
    });

    $migration = require database_path('migrations/0001_01_01_000007_create_job_history_table.php');
    $migration->up();

    expect(Schema::hasTable('jobs'))->toBeFalse()
        ->and(Schema::hasTable('job_history'))->toBeTrue();
});

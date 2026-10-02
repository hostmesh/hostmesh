<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

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
    expect(Schema::hasTable('failed_jobs'))->toBeTrue();
});

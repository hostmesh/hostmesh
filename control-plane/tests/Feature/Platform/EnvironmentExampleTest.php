<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Dotenv\Dotenv;

it('points the example environment at the development stack', function (): void {
    $example = Dotenv::parse((string) file_get_contents(base_path('.env.example')));

    expect($example)->toMatchArray([
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => 'postgres',
        'DB_PORT' => '5432',
        'DB_DATABASE' => 'hostmesh',
        'DB_USERNAME' => 'hostmesh',
        'QUEUE_CONNECTION' => 'redis',
        'CACHE_STORE' => 'redis',
        'REDIS_CLIENT' => 'phpredis',
        'REDIS_HOST' => 'redis',
    ]);
});

<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Dotenv\Dotenv;

it('points the example environment at the development stack', function (): void {
    $example = Dotenv::parse((string) file_get_contents(base_path('.env.example')));

    expect($example)->toMatchArray([
        'HOSTMESH_DOMAIN' => 'hostmesh.test',
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => 'postgres',
        'DB_PORT' => '5432',
        'DB_DATABASE' => 'hostmesh',
        'DB_USERNAME' => 'hostmesh',
        'QUEUE_CONNECTION' => 'redis',
        'CACHE_STORE' => 'redis',
        'REDIS_CLIENT' => 'phpredis',
        'REDIS_HOST' => 'redis',
    ])
        // The API URL derives from HOSTMESH_DOMAIN (spec section 28).
        ->and($example)->not->toHaveKey('APP_URL');
});

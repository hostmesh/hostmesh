<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Domain\Identity\Administrator;
use App\Domain\Identity\User;

// Spec section 28: one session guard per route group, each backed by its
// own table. A portal account can never satisfy the admin guard. Laravel
// merges its default `web` guard into this file; AppServiceProvider
// removes it.

return [
    // Laravel needs a default. App\Http\Middleware\UseSessionRealm sets the
    // guard of the route group for every request that has a session.
    'defaults' => [
        'guard' => 'customer',
        'passwords' => 'users',
    ],

    'guards' => [
        'admin' => ['driver' => 'session', 'provider' => 'administrators'],
        'customer' => ['driver' => 'session', 'provider' => 'users'],
    ],

    'providers' => [
        'administrators' => ['driver' => 'eloquent', 'model' => Administrator::class],
        'users' => ['driver' => 'eloquent', 'model' => User::class],
    ],
];

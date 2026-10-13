<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

// Spec section 25, 35 (non-negotiable 6) and 37. Eloquent write methods in
// Http are covered by PHPStan (phpstan/http-no-database-writes.neon),
// because arch() only sees class-level dependencies.

arch('the domain layer depends on neither Http nor Infrastructure')
    ->expect('App\Domain')
    ->not->toUse(['App\Http', 'App\Infrastructure']);

arch('Http does not touch the database directly')
    ->expect('App\Http')
    ->not->toUse([
        'DB',
        'Illuminate\Support\Facades\DB',
        'Illuminate\Support\Facades\Schema',
        'Illuminate\Database\DatabaseManager',
        'Illuminate\Database\ConnectionInterface',
        'Illuminate\Database\Connection',
        'Illuminate\Database\Query\Builder',
    ]);

// Console commands are an entry point next to Http: they call the
// application layer, and nothing calls them.
arch('nothing depends on the console commands')
    ->expect('App\Console')
    ->toOnlyBeUsedIn(['App\Console', 'App\Providers']);

foreach (['dd', 'ddd', 'dump', 'ray', 'var_dump', 'print_r'] as $helper) {
    arch("the debugging helper $helper() is not used")
        ->expect($helper)
        ->not->toBeUsed();
}

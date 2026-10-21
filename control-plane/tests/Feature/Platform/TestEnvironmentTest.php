<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

// Issue #231: end-to-end mode gives the php container APP_ENV=e2e, its own
// database and its own Redis databases (decision 0016). The suite must
// still run as `testing`, against the test database, whatever the
// container sets: RefreshDatabase rebuilds the database it runs against.

it('runs in the testing environment', function (): void {
    expect(app()->environment())->toBe('testing');
});

// A parallel run appends the worker's token to the name.
it('runs against the test database', function (): void {
    expect(config('database.connections.pgsql.database'))->toStartWith('hostmesh_test');
});

it('runs against the Redis database of the tests', function (): void {
    expect(config('database.redis.default.database'))->toBe('15');
});

// A variable the container sets wins over phpunit.xml twice over. PHPUnit
// leaves the environment alone unless an <env> entry is forced, and even a
// forced one does not reach $_SERVER, which Laravel reads first. Child
// processes inherit the environment, the application reads $_SERVER.
it('sets every variable in the environment and in $_SERVER', function (): void {
    $configuration = simplexml_load_file(base_path('phpunit.xml'));
    assert($configuration !== false);

    $values = fn (string $element): array => collect($configuration->php->{$element})
        ->mapWithKeys(fn (SimpleXMLElement $variable): array => [(string) $variable['name'] => (string) $variable['value']])
        ->all();

    $unforced = collect($configuration->php->env)
        ->reject(fn (SimpleXMLElement $variable): bool => (string) $variable['force'] === 'true')
        ->map(fn (SimpleXMLElement $variable): string => (string) $variable['name'])
        ->values()
        ->all();

    expect($values('env'))->not->toBeEmpty()
        ->and($unforced)->toBe([])
        ->and($values('server'))->toBe($values('env'));
});

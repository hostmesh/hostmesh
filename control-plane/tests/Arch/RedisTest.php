<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

// Spec section 24 and non-negotiable 7: Redis is never used for
// correctness. The application reaches it only through Laravel's queue,
// cache and rate limiter (decision 0014).

arch('the application never talks to Redis directly')
    ->expect('App')
    ->not->toUse([
        'Redis',
        'Illuminate\Support\Facades\Redis',
        'Illuminate\Redis\RedisManager',
        'Illuminate\Redis\Connections\Connection',
        'Illuminate\Contracts\Redis\Factory',
        'Illuminate\Contracts\Redis\Connection',
    ]);

// A lock, a unique job, an overlap guard or a cache-backed counter would
// make a decision depend on Redis. arch() cannot see a call made through
// the Cache facade, and PHPStan does not resolve it either, so this reads
// the sources. Spec section 24 permits best-effort locks: when the first
// real one is needed, add its file to an allow-list here, with the reason.
// `->lockForUpdate()` is a PostgreSQL row lock and is not matched.
it('uses no lock, overlap guard or cache-backed counter', function (): void {
    $forbidden = [
        '->lock(',
        '::lock(',
        'restoreLock(',
        'withoutOverlapping(',
        'onOneServer(',
        'maxExceptions',
        'ShouldBeUnique',
        'WithoutOverlapping',
        'ThrottlesExceptions',
    ];

    $root = dirname(__DIR__, 2);
    $files = [$root.'/bootstrap/app.php'];

    foreach (['app', 'routes'] as $directory) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator("$root/$directory", FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }

    $offences = [];

    foreach ($files as $path) {
        $source = (string) file_get_contents($path);

        foreach ($forbidden as $needle) {
            if (str_contains($source, $needle)) {
                $offences[] = substr($path, strlen($root) + 1).' contains '.$needle;
            }
        }
    }

    expect($files)->not->toBeEmpty()
        ->and($offences)->toBe([]);
});

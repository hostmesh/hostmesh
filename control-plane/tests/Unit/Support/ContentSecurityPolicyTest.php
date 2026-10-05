<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Support\ContentSecurityPolicy;
use App\Support\Hostnames;

it('renders the directives in order and fills in the API origin', function (): void {
    $policy = new ContentSecurityPolicy(new Hostnames('example.com'), [
        'portal' => [
            'default-src' => ["'none'"],
            'img-src' => ["'self'", 'data:'],
            'connect-src' => ['{api}'],
        ],
    ]);

    expect($policy->header('portal'))
        ->toBe("default-src 'none'; img-src 'self' data:; connect-src https://api.example.com");
});

it('follows the base domain', function (): void {
    $policies = ['admin' => ['connect-src' => ['{api}']]];

    expect((new ContentSecurityPolicy(new Hostnames('example.org'), $policies))->header('admin'))
        ->toBe('connect-src https://api.example.org');
});

it('refuses an app without a policy', function (): void {
    $policy = new ContentSecurityPolicy(new Hostnames('example.com'), []);

    expect(fn () => $policy->header('portal'))->toThrow(InvalidArgumentException::class, 'portal');
});

it('refuses malformed configuration', function (mixed $config): void {
    expect(fn () => ContentSecurityPolicy::fromConfig($config, new Hostnames('example.com')))
        ->toThrow(InvalidArgumentException::class, 'hostmesh.csp');
})->with([
    'not an array' => ['nope'],
    'policy not an array' => [['portal' => 'nope']],
    'sources not a list' => [['portal' => ['connect-src' => '{api}']]],
    'source not a string' => [['portal' => ['connect-src' => [1]]]],
]);

<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Support\Hostnames;

it('derives every host and origin from the base domain', function (): void {
    $hostnames = new Hostnames('example.com');

    expect($hostnames->domain)->toBe('example.com')
        ->and($hostnames->portalOrigin())->toBe('https://app.example.com')
        ->and($hostnames->adminOrigin())->toBe('https://admin.example.com')
        ->and($hostnames->apiOrigin())->toBe('https://api.example.com')
        ->and($hostnames->apiHost())->toBe('api.example.com')
        ->and($hostnames->agentHost())->toBe('agent.example.com')
        ->and($hostnames->host('app'))->toBe('app.example.com')
        ->and($hostnames->origin('admin'))->toBe('https://admin.example.com')
        ->and($hostnames->spaOrigins())->toBe(['https://app.example.com', 'https://admin.example.com']);
});

it('accepts a multi-label domain and lowercases it', function (): void {
    expect((new Hostnames(' Hosting.Example.CO.UK '))->apiHost())->toBe('api.hosting.example.co.uk');
});

it('refuses anything that is not a bare domain name', function (string $domain): void {
    expect(fn () => new Hostnames($domain))
        ->toThrow(InvalidArgumentException::class, 'HOSTMESH_DOMAIN');
})->with([
    'empty' => '',
    'single label' => 'localhost',
    'scheme' => 'https://example.com',
    'path' => 'example.com/',
    'port' => 'example.com:8443',
    'leading dot' => '.example.com',
    'trailing dot' => 'example.com.',
    'wildcard' => '*.example.com',
    'space' => 'exa mple.com',
    'underscore' => 'my_host.example.com',
]);

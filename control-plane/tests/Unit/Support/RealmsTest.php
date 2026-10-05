<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Support\Hostnames;
use App\Support\Realms;

function realmSettings(array $overrides = []): array
{
    return ['admin' => $overrides + [
        'guard' => 'admin',
        'subdomain' => 'admin',
        'session_cookie' => '__Host-hostmesh-admin-session',
        'session_table' => 'admin_sessions',
        'csrf_cookie' => 'XSRF-TOKEN-ADMIN',
        'session_lifetime' => 30,
    ]];
}

it('builds a realm from its settings and the hostnames', function (): void {
    $realm = Realms::fromConfig(realmSettings(), new Hostnames('example.com'))->get('admin');

    expect($realm->name)->toBe('admin')
        ->and($realm->guard)->toBe('admin')
        ->and($realm->origin)->toBe('https://admin.example.com')
        ->and($realm->sessionCookie)->toBe('__Host-hostmesh-admin-session')
        ->and($realm->sessionTable)->toBe('admin_sessions')
        ->and($realm->csrfCookie)->toBe('XSRF-TOKEN-ADMIN')
        ->and($realm->sessionLifetime)->toBe(30)
        ->and($realm->cookieDomain)->toBe('example.com');
});

it('refuses an unknown realm', function (): void {
    $realms = Realms::fromConfig(realmSettings(), new Hostnames('example.com'));

    expect(fn () => $realms->get('reseller'))->toThrow(InvalidArgumentException::class, 'reseller');
});

it('refuses a lifetime that is not a positive number of minutes', function (mixed $lifetime): void {
    expect(fn () => Realms::fromConfig(realmSettings(['session_lifetime' => $lifetime]), new Hostnames('example.com')))
        ->toThrow(InvalidArgumentException::class, 'session_lifetime');
})->with([0, -5, '30', null]);

it('refuses a missing setting', function (string $key): void {
    $settings = realmSettings();
    unset($settings['admin'][$key]);

    expect(fn () => Realms::fromConfig($settings, new Hostnames('example.com')))
        ->toThrow(InvalidArgumentException::class, $key);
})->with(['guard', 'subdomain', 'session_cookie', 'session_table', 'csrf_cookie']);

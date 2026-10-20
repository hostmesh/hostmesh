<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Support\TrustedProxies;

// Issue #204, decision 0018: the proxies whose X-Forwarded-For is believed.

it('trusts no proxy by default', function (string $setting): void {
    expect(TrustedProxies::fromSetting($setting)->addresses)->toBe([]);
})->with(['empty' => '', 'blank' => '  ']);

it('reads addresses and ranges of both families, separated by commas', function (): void {
    $proxies = TrustedProxies::fromSetting(' 192.0.2.10, 198.51.100.0/24,2001:db8::1 , 2001:db8:abcd::/48 ');

    expect($proxies->addresses)->toBe(['192.0.2.10', '198.51.100.0/24', '2001:db8::1', '2001:db8:abcd::/48']);
});

it('skips empty entries', function (): void {
    expect(TrustedProxies::fromSetting('192.0.2.10,,198.51.100.7,')->addresses)->toBe(['192.0.2.10', '198.51.100.7']);
});

it('refuses an entry that is not an address or a range', function (string $setting, string $entry): void {
    expect(fn () => TrustedProxies::fromSetting($setting))
        ->toThrow(InvalidArgumentException::class, "HOSTMESH_TRUSTED_PROXIES must list IP addresses or CIDR ranges, separated by commas. [$entry] is neither.");
})->with([
    'a hostname' => ['proxy.example.com', 'proxy.example.com'],
    'a broken address' => ['192.0.2.300', '192.0.2.300'],
    'a prefix too long for IPv4' => ['192.0.2.0/33', '192.0.2.0/33'],
    'a prefix too long for IPv6' => ['2001:db8::/129', '2001:db8::/129'],
    'a prefix that is not a number' => ['192.0.2.0/abc', '192.0.2.0/abc'],
    'an empty prefix' => ['192.0.2.0/', '192.0.2.0/'],
    'a negative prefix' => ['192.0.2.0/-1', '192.0.2.0/-1'],
    'spaces as separators' => ['192.0.2.10 198.51.100.7', '192.0.2.10 198.51.100.7'],
    'a bad entry after good ones' => ['192.0.2.10,nope', 'nope'],
]);

// Trusting whoever connects lets anyone who reaches the server directly
// choose the address the rate limits and the audit log see.
it('refuses to trust everyone', function (string $entry): void {
    expect(fn () => TrustedProxies::fromSetting("192.0.2.10,$entry"))
        ->toThrow(InvalidArgumentException::class, "HOSTMESH_TRUSTED_PROXIES does not accept [$entry]: it would trust every address. Name the proxies' addresses or ranges.");
})->with(['*', '**', 'REMOTE_ADDR', '0.0.0.0/0', '::/0']);

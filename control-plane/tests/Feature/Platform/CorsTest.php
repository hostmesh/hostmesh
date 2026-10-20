<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Testing\TestResponse;
use Tests\TestCase;

// Spec sections 28 and 37: CORS allows exactly the two SPA origins.

function corsPreflight(TestCase $test, string $origin, array $cookies = []): TestResponse
{
    return $test->call('OPTIONS', 'https://api.hostmesh.test/api/v1/admin/auth/login', [], $cookies, [], [
        'HTTP_ORIGIN' => $origin,
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type,x-xsrf-token',
    ]);
}

function corsRequest(TestCase $test, string $origin, array $cookies = []): TestResponse
{
    return $test->call('GET', 'https://api.hostmesh.test/api/v1/health', [], $cookies, [], [
        'HTTP_ORIGIN' => $origin,
    ]);
}

dataset('allowed origins', [
    'portal' => 'https://app.hostmesh.test',
    'admin' => 'https://admin.hostmesh.test',
]);

dataset('refused origins', [
    'foreign site' => 'https://evil.example',
    'the API itself' => 'https://api.hostmesh.test',
    'the agent host' => 'https://agent.hostmesh.test',
    'the bare domain' => 'https://hostmesh.test',
    'another subdomain' => 'https://blog.hostmesh.test',
    'plain http' => 'http://app.hostmesh.test',
    'another port' => 'https://app.hostmesh.test:8443',
    'suffix look-alike' => 'https://app.hostmesh.test.evil.example',
    'prefix look-alike' => 'https://evilapp.hostmesh.test',
    'null origin' => 'null',
]);

it('answers a preflight from an SPA origin with credentials allowed', function (string $origin): void {
    $response = corsPreflight($this, $origin);

    $response->assertNoContent()
        ->assertHeader('Access-Control-Allow-Origin', $origin)
        ->assertHeader('Access-Control-Allow-Credentials', 'true');

    expect($response->headers->get('Access-Control-Allow-Methods'))->toContain('POST')
        ->and($response->headers->get('Access-Control-Allow-Headers'))->toContain('x-xsrf-token', 'content-type')
        ->and($response->headers->get('Vary'))->toContain('Origin');
})->with('allowed origins');

it('lets an SPA origin read a response, with credentials', function (string $origin): void {
    corsRequest($this, $origin)
        ->assertOk()
        ->assertHeader('Access-Control-Allow-Origin', $origin)
        ->assertHeader('Access-Control-Allow-Credentials', 'true');
})->with('allowed origins');

it('gives no CORS headers to any other origin', function (string $origin): void {
    foreach ([corsPreflight($this, $origin), corsRequest($this, $origin)] as $response) {
        $response
            ->assertHeaderMissing('Access-Control-Allow-Origin')
            ->assertHeaderMissing('Access-Control-Allow-Credentials')
            ->assertHeaderMissing('Access-Control-Allow-Methods')
            ->assertHeaderMissing('Access-Control-Allow-Headers');
    }
})->with('refused origins');

it('gives no CORS headers to another origin that sends cookies', function (string $origin): void {
    $cookies = ['__Host-hostmesh-admin-session' => 'anything', 'XSRF-TOKEN-ADMIN' => 'anything'];

    foreach ([corsPreflight($this, $origin, $cookies), corsRequest($this, $origin, $cookies)] as $response) {
        $response
            ->assertHeaderMissing('Access-Control-Allow-Origin')
            ->assertHeaderMissing('Access-Control-Allow-Credentials');
    }
})->with('refused origins');

it('never answers with a wildcard origin', function (): void {
    expect(config('cors.allowed_origins'))->not->toContain('*')
        ->and(config('cors.allowed_origins_patterns'))->toBe([])
        ->and(config('cors.allowed_headers'))->not->toContain('*')
        ->and(config('cors.supports_credentials'))->toBeTrue();
});

// Issue #149: every API response carries X-Request-Id, and the audit log
// stores it. The browser hides a response header from the app unless CORS
// exposes it, so the apps could not show it with an error.
it('lets each SPA read the request ID of a response in its realm', function (string $origin, string $path): void {
    $response = $this->call('GET', "https://api.hostmesh.test/api/v1/$path", [], [], [], ['HTTP_ORIGIN' => $origin]);

    $response->assertNoContent()
        ->assertHeader('Access-Control-Allow-Origin', $origin)
        ->assertHeader('Access-Control-Expose-Headers', 'X-Request-Id');

    expect($response->headers->get('X-Request-Id'))->not->toBeNull();
})->with([
    'portal' => ['https://app.hostmesh.test', 'customer/auth/csrf-cookie'],
    'admin' => ['https://admin.hostmesh.test', 'admin/auth/csrf-cookie'],
]);

it('exposes no header to any other origin', function (string $origin): void {
    corsRequest($this, $origin)->assertHeaderMissing('Access-Control-Expose-Headers');
})->with('refused origins');

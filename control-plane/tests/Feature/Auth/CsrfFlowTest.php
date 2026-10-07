<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Browser;

// Spec section 28: the CSRF cookie flow is used. Each app has its own
// CSRF cookie, readable by its JavaScript, and echoes it in X-XSRF-TOKEN.

uses(RefreshDatabase::class);

it('sets a CSRF cookie that the app can read, on the parent domain', function (
    string $origin, string $prefix, string $sessionCookie, string $csrfCookie,
): void {
    $response = (new Browser($this))->get($origin, $prefix.'/auth/csrf-cookie');

    $response->assertNoContent();

    $cookie = $response->getCookie($csrfCookie, false);

    expect($cookie)->not->toBeNull()
        ->and($cookie->getDomain())->toBe('hostmesh.test')
        ->and($cookie->getPath())->toBe('/')
        ->and($cookie->isHttpOnly())->toBeFalse()
        ->and($cookie->isSecure())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('lax')
        ->and($response->getCookie('XSRF-TOKEN', false))->toBeNull();
})->with('realms');

it('accepts a state-changing request that echoes the cookie in X-XSRF-TOKEN', function (
    string $origin,
): void {
    $account = Browser::createAccount($origin);

    (new Browser($this))->logIn($origin, $account->email)->assertOk();
})->with('realms');

it('rejects a state-changing request without the token', function (string $origin, string $prefix): void {
    $account = Browser::createAccount($origin);
    $browser = new Browser($this);
    $browser->get($origin, $prefix.'/auth/csrf-cookie');

    $browser->usingCsrfToken(false)
        ->post($origin, $prefix.'/auth/login', ['email' => $account->email, 'password' => Browser::PASSWORD])
        ->assertStatus(419);

    $browser->usingCsrfToken(null)->get($origin, $prefix.'/auth/user')->assertUnauthorized();
})->with('realms');

it('rejects a state-changing request with a made-up token', function (string $origin, string $prefix): void {
    $account = Browser::createAccount($origin);
    $browser = new Browser($this);
    $browser->get($origin, $prefix.'/auth/csrf-cookie');

    $browser->usingCsrfToken('made-up')
        ->post($origin, $prefix.'/auth/login', ['email' => $account->email, 'password' => Browser::PASSWORD])
        ->assertStatus(419);
})->with('realms');

it('rejects a request without any prior CSRF cookie', function (string $origin, string $prefix): void {
    $account = Browser::createAccount($origin);

    (new Browser($this))
        ->post($origin, $prefix.'/auth/login', ['email' => $account->email, 'password' => Browser::PASSWORD])
        ->assertStatus(419);
})->with('realms');

it('rejects the token of the other app', function (): void {
    $account = Browser::createAccount(Browser::PORTAL);
    $browser = new Browser($this);
    $browser->get(Browser::ADMIN, '/api/v1/admin/auth/csrf-cookie');
    $browser->get(Browser::PORTAL, '/api/v1/customer/auth/csrf-cookie');

    $browser->usingCsrfToken($browser->cookie(Browser::ADMIN_CSRF))
        ->post(Browser::PORTAL, '/api/v1/customer/auth/login', ['email' => $account->email, 'password' => Browser::PASSWORD])
        ->assertStatus(419);
});

it('requires the token to log out', function (string $origin, string $prefix): void {
    $account = $origin === Browser::ADMIN ? Browser::createEnrolled($origin) : Browser::createAccount($origin);
    $browser = new Browser($this);
    $browser->signIn($origin, $account);

    $browser->usingCsrfToken(false)->post($origin, $prefix.'/auth/logout')->assertStatus(419);
    $browser->usingCsrfToken(null)->get($origin, $prefix.'/auth/user')->assertOk();
})->with('realms');

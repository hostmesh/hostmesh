<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Domain\Identity\Administrator;
use App\Domain\Identity\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Browser;

// Spec sections 28 and 37: admin and portal sessions are isolated. Each
// route group has its own guard, session cookie and session table, and
// accepts only its own SPA origin.

uses(RefreshDatabase::class);

it('refuses a portal page that calls an admin endpoint', function (): void {
    $browser = new Browser($this);
    $browser->logIn(Browser::PORTAL, User::factory()->create()->email)->assertOk();

    $browser->get(Browser::PORTAL, '/api/v1/admin/auth/user')->assertForbidden();
    $browser->post(Browser::PORTAL, '/api/v1/admin/auth/logout')->assertForbidden();
});

it('refuses an admin page that calls a portal endpoint', function (): void {
    $browser = new Browser($this);
    $browser->logIn(Browser::ADMIN, Administrator::factory()->create()->email)->assertOk();

    $browser->get(Browser::ADMIN, '/api/v1/customer/auth/user')->assertForbidden();
    $browser->post(Browser::ADMIN, '/api/v1/customer/auth/logout')->assertForbidden();
});

it('does not accept a portal session under the admin cookie name', function (): void {
    $browser = new Browser($this);
    $browser->logIn(Browser::PORTAL, User::factory()->create()->email)->assertOk();

    $browser->forge(Browser::ADMIN_SESSION, $browser->sessionId(Browser::PORTAL_SESSION));

    $browser->get(Browser::ADMIN, '/api/v1/admin/auth/user')->assertUnauthorized();
});

it('does not accept an admin session under the portal cookie name', function (): void {
    $browser = new Browser($this);
    $browser->logIn(Browser::ADMIN, Administrator::factory()->create()->email)->assertOk();

    $browser->forge(Browser::PORTAL_SESSION, $browser->sessionId(Browser::ADMIN_SESSION));

    $browser->get(Browser::PORTAL, '/api/v1/customer/auth/user')->assertUnauthorized();
});

it('does not let portal credentials log in to the admin API', function (): void {
    $user = User::factory()->create();

    (new Browser($this))->logIn(Browser::ADMIN, $user->email)->assertUnprocessable();
});

it('does not let admin credentials log in to the portal API', function (): void {
    $administrator = Administrator::factory()->create();

    (new Browser($this))->logIn(Browser::PORTAL, $administrator->email)->assertUnprocessable();
});

it('keeps both sessions alive side by side in one browser', function (): void {
    $administrator = Administrator::factory()->withTwoFactor()->create();
    $user = User::factory()->create();
    $browser = new Browser($this);

    $browser->signIn(Browser::ADMIN, $administrator);
    $browser->logIn(Browser::PORTAL, $user->email)->assertOk();

    $browser->get(Browser::ADMIN, '/api/v1/admin/auth/user')->assertOk()->assertJsonPath('data.id', $administrator->id);
    $browser->get(Browser::PORTAL, '/api/v1/customer/auth/user')->assertOk()->assertJsonPath('data.id', $user->id);

    $browser->post(Browser::PORTAL, '/api/v1/customer/auth/logout')->assertNoContent();

    $browser->get(Browser::PORTAL, '/api/v1/customer/auth/user')->assertUnauthorized();
    $browser->get(Browser::ADMIN, '/api/v1/admin/auth/user')->assertOk();
});

it('sets only the cookies of its own realm', function (
    string $origin, string $prefix, string $sessionCookie, string $csrfCookie,
): void {
    $response = (new Browser($this))->get($origin, $prefix.'/auth/csrf-cookie');

    $names = collect($response->headers->getCookies())->map->getName()->sort()->values()->all();

    expect($names)->toBe(collect([$sessionCookie, $csrfCookie])->sort()->values()->all());
})->with('realms');

it('sets a host-only, Secure, HttpOnly, SameSite=Lax session cookie', function (
    string $origin, string $prefix, string $sessionCookie,
): void {
    $cookie = (new Browser($this))->get($origin, $prefix.'/auth/csrf-cookie')->getCookie($sessionCookie, false);

    expect($cookie)->not->toBeNull()
        ->and($cookie->isSecure())->toBeTrue()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('lax')
        ->and($cookie->getDomain())->toBeNull()
        ->and($cookie->getPath())->toBe('/');
})->with('realms');

it('gives the session cookie the lifetime of its realm', function (
    string $origin, string $prefix, string $sessionCookie, string $csrfCookie, int $minutes,
): void {
    $this->freezeTime();

    $cookie = (new Browser($this))->get($origin, $prefix.'/auth/csrf-cookie')->getCookie($sessionCookie, false);

    expect($cookie->getExpiresTime())->toBe(now()->addMinutes($minutes)->getTimestamp());
})->with('realms');

it('expires an idle admin session sooner than a portal session', function (): void {
    $browser = new Browser($this);
    $browser->logIn(Browser::ADMIN, Administrator::factory()->create()->email)->assertOk();
    $browser->logIn(Browser::PORTAL, User::factory()->create()->email)->assertOk();

    $this->travel(31)->minutes();

    $browser->get(Browser::ADMIN, '/api/v1/admin/auth/user')->assertUnauthorized();
    $browser->get(Browser::PORTAL, '/api/v1/customer/auth/user')->assertOk();

    $this->travel(121)->minutes();

    $browser->get(Browser::PORTAL, '/api/v1/customer/auth/user')->assertUnauthorized();
});

// The session row names its account, so a session can be ended by account
// (issue #136). One process serves the whole test, as a long-running
// server would: the guard that names the account must be this request's.
it('records the account of each session in the table of its realm', function (): void {
    $administrator = Administrator::factory()->withTwoFactor()->create();
    $user = User::factory()->create();
    $browser = new Browser($this);

    $browser->signIn(Browser::ADMIN, $administrator);
    $browser->logIn(Browser::PORTAL, $user->email)->assertOk();

    expect(DB::table('admin_sessions')->where('id', $browser->sessionId(Browser::ADMIN_SESSION))->value('user_id'))
        ->toBe($administrator->id)
        ->and(DB::table('portal_sessions')->where('id', $browser->sessionId(Browser::PORTAL_SESSION))->value('user_id'))
        ->toBe($user->id);
});

it('does not let admin garbage collection delete a live portal session', function (): void {
    config(['session.lottery' => [1, 1]]); // every request collects garbage

    $browser = new Browser($this);
    $browser->logIn(Browser::PORTAL, User::factory()->create()->email)->assertOk();

    $this->travel(45)->minutes();

    $browser->get(Browser::ADMIN, '/api/v1/admin/auth/csrf-cookie')->assertNoContent();

    $browser->get(Browser::PORTAL, '/api/v1/customer/auth/user')->assertOk();
});

it('refuses a request without an Origin header', function (string $origin, string $prefix): void {
    $this->getJson('https://api.hostmesh.test'.$prefix.'/auth/user')->assertForbidden();
    $this->getJson('https://api.hostmesh.test'.$prefix.'/auth/csrf-cookie')->assertForbidden();
    $this->postJson('https://api.hostmesh.test'.$prefix.'/auth/login', [
        'email' => 'jane@example.com', 'password' => 'correct-password',
    ])->assertForbidden();
})->with('realms');

it('refuses any origin but its own', function (string $origin, string $prefix): void {
    foreach (['null', 'https://evil.example', 'https://api.hostmesh.test', 'http'.substr($origin, 5), $origin.':8443'] as $other) {
        (new Browser($this))->get($other, $prefix.'/auth/csrf-cookie')
            ->assertForbidden()
            ->assertHeaderMissing('Set-Cookie');
    }
})->with('realms');

it('keeps the public endpoints free of sessions and origin checks', function (): void {
    $this->getJson('https://api.hostmesh.test/api/v1/health')
        ->assertOk()
        ->assertHeaderMissing('Set-Cookie');
});

it('defines no guard besides the two realms and Sanctum', function (): void {
    expect(array_keys(config('auth.guards')))->toEqualCanonicalizing(['admin', 'customer', 'sanctum'])
        ->and(config('auth.guards.admin'))->toBe(['driver' => 'session', 'provider' => 'administrators'])
        ->and(config('auth.guards.customer'))->toBe(['driver' => 'session', 'provider' => 'users'])
        ->and(config('sanctum.routes'))->toBeFalse();
});

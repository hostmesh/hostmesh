<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Domain\Identity\Administrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Tests\Support\Browser;

// Issue #25, spec sections 28 and 37: admin routes require two-factor
// authentication. These tests read the route list, so an admin route that
// is added later is covered without touching this file. A new route that
// must work before the second factor has to be added to the list below,
// which makes it a visible decision in review.

uses(RefreshDatabase::class);

const BEFORE_SECOND_FACTOR = [
    'admin.auth.csrf-cookie',
    'admin.auth.login',
    'admin.auth.logout',
    'admin.auth.two-factor.challenge',
];

// Behind the gate, but open to an administrator who has not enrolled yet.
const ENROLLMENT = [
    'admin.auth.two-factor.enroll',
    'admin.auth.two-factor.confirm',
];

/** @return Collection<int, RoutingRoute> */
function adminRoutes(): Collection
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => str_starts_with($route->uri(), 'api/v1/admin'))
        ->values();
}

/** @return Collection<int, RoutingRoute> */
function gatedAdminRoutes(): Collection
{
    return adminRoutes()
        ->filter(fn (RoutingRoute $route): bool => in_array('two-factor', $route->gatherMiddleware(), true))
        ->values();
}

/** @return list<array{string, string}> method and URI of every call a route accepts */
function callsOf(RoutingRoute $route): array
{
    $uri = '/'.preg_replace('/\{[^}]+\}/', '00000000-0000-0000-0000-000000000000', $route->uri());

    return array_map(
        fn (string $method): array => [$method, $uri],
        array_values(array_diff($route->methods(), ['HEAD'])),
    );
}

beforeEach(function (): void {
    $this->freezeTime();
});

it('names every admin route', function (): void {
    expect(adminRoutes())->not->toBeEmpty();

    foreach (adminRoutes() as $route) {
        expect($route->getName())->not->toBeNull("{$route->uri()} has no name");
    }
});

it('leaves only the documented admin routes outside the gate', function (): void {
    $outside = adminRoutes()
        ->reject(fn (RoutingRoute $route): bool => in_array('two-factor', $route->gatherMiddleware(), true))
        ->map(fn (RoutingRoute $route): ?string => $route->getName())
        ->all();

    expect($outside)->toEqualCanonicalizing(BEFORE_SECOND_FACTOR);
});

it('puts authentication in front of the gate on every gated admin route', function (): void {
    foreach (gatedAdminRoutes() as $route) {
        $middleware = $route->gatherMiddleware();

        expect($middleware)->toContain('realm:admin', 'auth:admin');
    }
});

it('rejects a password-only session of an enrolled administrator on every gated admin route', function (): void {
    $browser = new Browser($this);
    $browser->logIn(Browser::ADMIN, Browser::createEnrolled(Browser::ADMIN)->email)->assertOk();

    expect(gatedAdminRoutes())->not->toBeEmpty();

    foreach (gatedAdminRoutes() as $route) {
        foreach (callsOf($route) as [$method, $uri]) {
            $browser->request($method, Browser::ADMIN, $uri)
                ->assertForbidden()
                ->assertExactJson([
                    'message' => 'Two-factor authentication is required.',
                    'code' => 'two_factor_required',
                ]);
        }
    }
});

it('rejects an administrator who has not enrolled on every gated admin route but enrollment', function (): void {
    $browser = new Browser($this);
    $browser->logIn(Browser::ADMIN, Browser::createAccount(Browser::ADMIN)->email)->assertOk();

    $routes = gatedAdminRoutes()->reject(fn (RoutingRoute $route): bool => in_array($route->getName(), ENROLLMENT, true));

    expect($routes)->not->toBeEmpty();

    foreach ($routes as $route) {
        foreach (callsOf($route) as [$method, $uri]) {
            $browser->request($method, Browser::ADMIN, $uri)
                ->assertForbidden()
                ->assertExactJson([
                    'message' => 'Two-factor authentication must be set up first.',
                    'code' => 'two_factor_enrollment_required',
                ]);
        }
    }
});

it('answers 403 before it looks up a route parameter', function (): void {
    // A route as later issues will add them, with a model in the path.
    Route::middleware(['api', 'realm:admin', 'auth:admin', 'two-factor'])
        ->get('api/v1/admin/probe/{administrator}', fn (Administrator $administrator): string => $administrator->id)
        ->name('admin.probe');

    $browser = new Browser($this);
    $browser->logIn(Browser::ADMIN, Browser::createEnrolled(Browser::ADMIN)->email)->assertOk();

    // 403, not 404: nothing about the resource leaks to half a login.
    $browser->get(Browser::ADMIN, '/api/v1/admin/probe/00000000-0000-0000-0000-000000000000')
        ->assertForbidden()
        ->assertJsonPath('code', 'two_factor_required');
});

it('lets an administrator through after the challenge', function (): void {
    $administrator = Browser::createEnrolled(Browser::ADMIN);
    $browser = new Browser($this);
    $browser->logIn(Browser::ADMIN, $administrator->email)->assertOk();

    $browser->get(Browser::ADMIN, '/api/v1/admin/auth/user')->assertForbidden();

    $browser->post(Browser::ADMIN, '/api/v1/admin/auth/two-factor/challenge', ['code' => Browser::code()])
        ->assertNoContent();

    $browser->get(Browser::ADMIN, '/api/v1/admin/auth/user')
        ->assertOk()
        ->assertJsonPath('data.id', $administrator->id)
        ->assertJsonPath('data.two_factor_enabled', true);
});

it('lets an administrator through after confirming enrollment', function (): void {
    $browser = new Browser($this);
    $browser->logIn(Browser::ADMIN, Browser::createAccount(Browser::ADMIN)->email)->assertOk();

    $secret = $browser->post(Browser::ADMIN, '/api/v1/admin/auth/two-factor')->assertOk()->json('data.secret');
    $browser->get(Browser::ADMIN, '/api/v1/admin/auth/user')->assertForbidden();

    $browser->post(Browser::ADMIN, '/api/v1/admin/auth/two-factor/confirm', ['code' => Browser::code($secret)])
        ->assertOk();

    $browser->get(Browser::ADMIN, '/api/v1/admin/auth/user')->assertOk();
});

it('does not let a stolen password replace the authenticator', function (): void {
    $administrator = Browser::createEnrolled(Browser::ADMIN);
    $browser = new Browser($this);
    $browser->logIn(Browser::ADMIN, $administrator->email)->assertOk();

    $browser->post(Browser::ADMIN, '/api/v1/admin/auth/two-factor')
        ->assertForbidden()->assertJsonPath('code', 'two_factor_required');
    $browser->post(Browser::ADMIN, '/api/v1/admin/auth/two-factor/confirm', ['code' => Browser::code()])
        ->assertForbidden();

    expect($administrator->fresh()->hasTwoFactor())->toBeTrue();
});

it('asks for the second factor again at every login', function (): void {
    $administrator = Browser::createEnrolled(Browser::ADMIN);
    $browser = new Browser($this);
    $browser->signIn(Browser::ADMIN, $administrator);
    $browser->get(Browser::ADMIN, '/api/v1/admin/auth/user')->assertOk();

    $browser->post(Browser::ADMIN, '/api/v1/admin/auth/logout')->assertNoContent();
    $browser->logIn(Browser::ADMIN, $administrator->email)->assertOk();

    $browser->get(Browser::ADMIN, '/api/v1/admin/auth/user')
        ->assertForbidden()->assertJsonPath('code', 'two_factor_required');
});

it('asks for the second factor again after the admin session expired', function (): void {
    $administrator = Browser::createEnrolled(Browser::ADMIN);
    $browser = new Browser($this);
    $browser->signIn(Browser::ADMIN, $administrator);

    // The admin idle lifetime is 30 minutes (decision 0010).
    $this->travel(31)->minutes();

    $browser->get(Browser::ADMIN, '/api/v1/admin/auth/user')->assertUnauthorized();

    $browser->logIn(Browser::ADMIN, $administrator->email)
        ->assertOk()->assertJsonPath('meta.second_factor', 'challenge');
    $browser->get(Browser::ADMIN, '/api/v1/admin/auth/user')->assertForbidden();

    $browser->post(Browser::ADMIN, '/api/v1/admin/auth/two-factor/challenge', ['code' => Browser::code()])
        ->assertNoContent();
    $browser->get(Browser::ADMIN, '/api/v1/admin/auth/user')->assertOk();
});

it('does not accept the mark of one administrator for another', function (): void {
    $first = Browser::createEnrolled(Browser::ADMIN);
    $second = Browser::createEnrolled(Browser::ADMIN);
    $browser = new Browser($this);
    $browser->signIn(Browser::ADMIN, $first);

    // Same browser, same session cookie, another account, no logout.
    $browser->logIn(Browser::ADMIN, $second->email)->assertOk();

    $browser->get(Browser::ADMIN, '/api/v1/admin/auth/user')->assertForbidden();
});

it('keeps two-factor optional for customers', function (): void {
    $plain = Browser::createAccount(Browser::PORTAL);
    $browser = new Browser($this);
    $browser->logIn(Browser::PORTAL, $plain->email)->assertOk();
    $browser->get(Browser::PORTAL, '/api/v1/customer/auth/user')->assertOk();

    $enrolled = Browser::createEnrolled(Browser::PORTAL);
    $other = new Browser($this);
    $other->logIn(Browser::PORTAL, $enrolled->email)->assertOk();
    $other->get(Browser::PORTAL, '/api/v1/customer/auth/user')
        ->assertForbidden()->assertJsonPath('code', 'two_factor_required');

    $other->post(Browser::PORTAL, '/api/v1/customer/auth/two-factor/challenge', ['code' => Browser::code()])
        ->assertNoContent();
    $other->get(Browser::PORTAL, '/api/v1/customer/auth/user')->assertOk();
});

it('answers 401, not 403, without a session', function (): void {
    $browser = new Browser($this);
    $browser->get(Browser::ADMIN, '/api/v1/admin/auth/csrf-cookie');

    foreach (gatedAdminRoutes() as $route) {
        foreach (callsOf($route) as [$method, $uri]) {
            $browser->request($method, Browser::ADMIN, $uri)->assertUnauthorized();
        }
    }
});

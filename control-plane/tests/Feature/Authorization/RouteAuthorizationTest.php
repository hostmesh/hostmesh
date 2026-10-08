<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\Support\Browser;

// Issue #26, decision 0012, spec section 28: authorization is enforced
// server-side. These tests read the route list, so a route that is added
// later is covered without touching this file: it fails here until it asks
// the Gate. A route with a parameter needs a record to exist before the
// policy can be asked; give it one in recordsFor() when the first such route
// arrives.
//
// The routes outside the `two-factor` block make up a login (CSRF cookie,
// login, logout, challenge) and act on nothing but the caller's own session.
// tests/Feature/Auth/SecondFactorGateTest.php keeps that list closed.

uses(RefreshDatabase::class);

/** @return Collection<int, RoutingRoute> every route of the realm behind authentication and the second factor */
function authorizedRoutes(string $prefix): Collection
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => str_starts_with('/'.$route->uri().'/', $prefix.'/'))
        ->filter(fn (RoutingRoute $route): bool => in_array('two-factor', $route->gatherMiddleware(), true))
        ->values();
}

beforeEach(function (): void {
    $this->freezeTime();
    // The Gate is under test, not the rate limit in front of it.
    $this->withoutMiddleware(ThrottleRequests::class);
});

it('asks the Gate on every route of a realm', function (string $origin, string $prefix): void {
    $browser = new Browser($this);
    $browser->signIn($origin, Browser::createEnrolled($origin));

    $checks = 0;
    Gate::after(function () use (&$checks): void {
        $checks++;
    });

    expect(authorizedRoutes($prefix))->not->toBeEmpty();

    foreach (authorizedRoutes($prefix) as $route) {
        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $checks = 0;

            $browser->request($method, $origin, '/'.$route->uri());

            expect($checks)->toBeGreaterThan(0, "$method /{$route->uri()} did not ask the Gate");
        }
    }
})->with('realms');

it('answers 403 on every route of a realm when the Gate says no', function (string $origin, string $prefix): void {
    $browser = new Browser($this);
    $browser->signIn($origin, Browser::createEnrolled($origin));

    Gate::before(fn (): bool => false);

    foreach (authorizedRoutes($prefix) as $route) {
        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $browser->request($method, $origin, '/'.$route->uri())
                ->assertForbidden()
                ->assertJsonPath('message', 'This action is unauthorized.')
                // Not the 403 of the second-factor gate.
                ->assertJsonMissingPath('code');
        }
    }
})->with('realms');

it('fails for a route that does not ask the Gate', function (): void {
    // Proof that the two tests above can fail: a route as a later issue
    // might add it, with authentication and no authorization.
    Route::middleware(['api', 'realm:admin', 'auth:admin', 'two-factor'])
        ->get('api/v1/admin/unguarded', fn (): array => ['ok' => true])
        ->name('admin.unguarded');

    $browser = new Browser($this);
    $browser->signIn(Browser::ADMIN, Browser::createEnrolled(Browser::ADMIN));

    Gate::before(fn (): bool => false);

    expect(authorizedRoutes('/api/v1/admin')->map(fn (RoutingRoute $route): string => $route->uri()))
        ->toContain('api/v1/admin/unguarded');

    $browser->get(Browser::ADMIN, '/api/v1/admin/unguarded')->assertOk();
});

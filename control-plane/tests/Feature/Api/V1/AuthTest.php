<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Browser;

// Issue #24: login, logout and current user, for administrators
// (/api/v1/admin) and for customers (/api/v1/customer).

uses(RefreshDatabase::class);

it('logs in and returns the account', function (string $origin): void {
    $account = Browser::createAccount($origin);

    (new Browser($this))->logIn($origin, $account->email)
        ->assertOk()
        ->assertExactJson([
            'data' => [
                'id' => $account->id,
                'name' => $account->name,
                'email' => $account->email,
                'two_factor_enabled' => false,
            ],
            'meta' => ['second_factor' => $origin === Browser::ADMIN ? 'enrollment' : 'none'],
        ]);
})->with('realms');

it('reports the current user', function (string $origin, string $prefix): void {
    $account = Browser::createAccount($origin);
    $browser = new Browser($this);
    $browser->logIn($origin, $account->email);

    $browser->get($origin, $prefix.'/auth/user')
        ->assertOk()
        ->assertExactJson(['data' => [
            'id' => $account->id,
            'name' => $account->name,
            'email' => $account->email,
            'two_factor_enabled' => false,
        ]]);
})->with('realms');

it('answers 401 when nobody is logged in', function (string $origin, string $prefix): void {
    $browser = new Browser($this);
    $browser->get($origin, $prefix.'/auth/csrf-cookie');

    $browser->get($origin, $prefix.'/auth/user')
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);

    $browser->post($origin, $prefix.'/auth/logout')->assertUnauthorized();
})->with('realms');

it('logs out and ends the session', function (string $origin, string $prefix, string $sessionCookie): void {
    $account = Browser::createAccount($origin);
    $browser = new Browser($this);
    $browser->logIn($origin, $account->email);
    $oldSession = $browser->sessionId($sessionCookie);

    $browser->post($origin, $prefix.'/auth/logout')->assertNoContent();

    $browser->get($origin, $prefix.'/auth/user')->assertUnauthorized();

    // The old session ID is dead on the server, not only forgotten by the browser.
    $replay = new Browser($this);
    $replay->forge($sessionCookie, $oldSession);
    $replay->get($origin, $prefix.'/auth/user')->assertUnauthorized();
})->with('realms');

it('issues a new session ID at login', function (string $origin, string $prefix, string $sessionCookie): void {
    $account = Browser::createAccount($origin);
    $browser = new Browser($this);
    $browser->get($origin, $prefix.'/auth/csrf-cookie');
    $before = $browser->sessionId($sessionCookie);

    $browser->post($origin, $prefix.'/auth/login', ['email' => $account->email, 'password' => Browser::PASSWORD])
        ->assertOk();

    expect($browser->sessionId($sessionCookie))->not->toBe($before);
})->with('realms');

it('answers a wrong password and an unknown email identically', function (string $origin, string $prefix): void {
    $account = Browser::createAccount($origin);

    $attempt = function (string $email) use ($origin): array {
        $response = (new Browser($this))->logIn($origin, $email, 'wrong-password');

        $headers = $response->headers->all();
        unset($headers['set-cookie'], $headers['date']);
        ksort($headers);

        return [$response->getStatusCode(), $response->getContent(), $headers];
    };

    $wrongPassword = $attempt($account->email);
    $unknownEmail = $attempt('nobody@example.com');

    expect($wrongPassword)->toBe($unknownEmail)
        ->and($wrongPassword[0])->toBe(422)
        ->and(json_decode($wrongPassword[1], true))->toBe([
            'message' => 'These credentials do not match our records.',
            'errors' => ['email' => ['These credentials do not match our records.']],
        ]);
})->with('realms');

it('accepts the email in any letter case', function (string $origin): void {
    $account = Browser::createAccount($origin);

    (new Browser($this))->logIn($origin, mb_strtoupper($account->email))
        ->assertOk()
        ->assertJsonPath('data.id', $account->id);
})->with('realms');

it('validates the input', function (string $origin, string $prefix): void {
    $browser = new Browser($this);
    $browser->get($origin, $prefix.'/auth/csrf-cookie');

    $browser->post($origin, $prefix.'/auth/login')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'password']);

    $browser->post($origin, $prefix.'/auth/login', ['email' => ['a@example.com'], 'password' => ['x']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'password']);

    $browser->post($origin, $prefix.'/auth/login', ['email' => 'not-an-email', 'password' => 'x'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
})->with('realms');

it('rate limits login attempts for one email from one address', function (string $origin, string $prefix): void {
    $account = Browser::createAccount($origin);
    $browser = new Browser($this);
    $browser->get($origin, $prefix.'/auth/csrf-cookie');

    foreach (range(1, 5) as $ignored) {
        $browser->post($origin, $prefix.'/auth/login', ['email' => $account->email, 'password' => 'wrong-password'])
            ->assertUnprocessable();
    }

    // Even the right password is refused now, in any letter case.
    $browser->post($origin, $prefix.'/auth/login', ['email' => mb_strtoupper($account->email), 'password' => Browser::PASSWORD])
        ->assertTooManyRequests()
        ->assertHeader('Retry-After');

    // Another email is unaffected.
    $browser->post($origin, $prefix.'/auth/login', ['email' => 'someone-else@example.com', 'password' => 'wrong-password'])
        ->assertUnprocessable();

    $this->travel(61)->seconds();

    $browser->post($origin, $prefix.'/auth/login', ['email' => $account->email, 'password' => Browser::PASSWORD])
        ->assertOk();
})->with('realms');

it('rate limits an unknown email exactly like a known one', function (string $origin, string $prefix): void {
    $account = Browser::createAccount($origin);

    $sixth = function (string $email) use ($origin, $prefix): array {
        $browser = new Browser($this);
        $browser->get($origin, $prefix.'/auth/csrf-cookie');

        foreach (range(1, 5) as $ignored) {
            $browser->post($origin, $prefix.'/auth/login', ['email' => $email, 'password' => 'wrong-password']);
        }

        $response = $browser->post($origin, $prefix.'/auth/login', ['email' => $email, 'password' => 'wrong-password']);

        return [$response->getStatusCode(), $response->getContent(), $response->headers->has('Retry-After')];
    };

    $this->freezeTime();

    expect($sixth($account->email))->toBe($sixth('nobody@example.com'))
        ->and($sixth('third@example.com')[0])->toBe(429);
})->with('realms');

it('rate limits one address across many emails', function (string $origin, string $prefix): void {
    $browser = new Browser($this);
    $browser->get($origin, $prefix.'/auth/csrf-cookie');

    foreach (range(1, 20) as $n) {
        $browser->post($origin, $prefix.'/auth/login', ['email' => "guess$n@example.com", 'password' => 'wrong-password'])
            ->assertUnprocessable();
    }

    $browser->post($origin, $prefix.'/auth/login', ['email' => 'guess21@example.com', 'password' => 'wrong-password'])
        ->assertTooManyRequests();
})->with('realms');

it('counts the two realms separately', function (): void {
    $user = Browser::createAccount(Browser::PORTAL);
    $browser = new Browser($this);
    $browser->get(Browser::ADMIN, '/api/v1/admin/auth/csrf-cookie');

    foreach (range(1, 6) as $ignored) {
        $browser->post(Browser::ADMIN, '/api/v1/admin/auth/login', ['email' => $user->email, 'password' => 'wrong-password']);
    }

    $browser->logIn(Browser::PORTAL, $user->email)->assertOk();
});

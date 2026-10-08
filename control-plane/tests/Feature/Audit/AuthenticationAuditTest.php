<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Application\Services\LoginAddressHasher;
use App\Domain\Audit\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\AuditTrail;
use Tests\Support\Browser;
use Tests\Support\InMemoryAuditLog;

// Issue #27, decision 0013: login and logout are audited in both realms.
// An event holds identifiers only, and an action whose event cannot be
// written does not happen.

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeTime();
});

function accountType(string $origin): string
{
    return $origin === Browser::ADMIN ? 'administrator' : 'user';
}

it('records a successful login', function (string $origin): void {
    $account = Browser::createAccount($origin);

    $response = (new Browser($this))->logIn($origin, $account->email)->assertOk();

    $event = AuditTrail::last();

    expect(AuditTrail::actions())->toBe(['auth.login.succeeded'])
        ->and($event->actor_type)->toBe(accountType($origin))
        ->and($event->actor_id)->toBe($account->id)
        ->and($event->resource)->toBe(accountType($origin))
        ->and($event->resource_id)->toBe($account->id)
        ->and(AuditTrail::metadata($event))->toBe([])
        ->and($event->ip_address)->toBe('127.0.0.1')
        ->and($event->user_agent)->toBe('Symfony')
        ->and(Str::isUuid($event->request_id))->toBeTrue()
        ->and($event->request_id)->toBe($response->headers->get('X-Request-Id'))
        ->and(strtotime($event->created_at))->toBe(now()->getTimestamp());
})->with('realms');

it('records a failed login for a known address against the account', function (string $origin): void {
    $account = Browser::createAccount($origin);

    (new Browser($this))->logIn($origin, $account->email, 'wrong-password')->assertUnprocessable();

    $event = AuditTrail::last();

    expect(AuditTrail::actions())->toBe(['auth.login.failed'])
        ->and($event->actor_type)->toBe(accountType($origin))
        ->and($event->actor_id)->toBe($account->id)
        ->and($event->resource_id)->toBe($account->id)
        ->and(AuditTrail::metadata($event))->toBe([])
        ->and($event->ip_address)->toBe('127.0.0.1');
})->with('realms');

it('records a failed login for an unknown address without an actor and without the address', function (string $origin): void {
    (new Browser($this))->logIn($origin, 'Nobody@Example.com')->assertUnprocessable();

    $event = AuditTrail::last();

    expect(AuditTrail::actions())->toBe(['auth.login.failed'])
        ->and($event->actor_type)->toBe(accountType($origin))
        ->and($event->actor_id)->toBeNull()
        ->and($event->resource)->toBe(accountType($origin))
        ->and($event->resource_id)->toBeNull()
        ->and(AuditTrail::metadata($event))->toBe([
            'email_hash' => app(LoginAddressHasher::class)->hash('nobody@example.com'),
        ]);

    AuditTrail::assertHoldsNone('nobody@example.com', 'nobody', Browser::PASSWORD);
})->with('realms');

it('lets attempts on one unknown address be correlated', function (): void {
    (new Browser($this))->logIn(Browser::ADMIN, 'nobody@example.com')->assertUnprocessable();
    (new Browser($this))->logIn(Browser::ADMIN, 'NOBODY@example.com')->assertUnprocessable();
    (new Browser($this))->logIn(Browser::ADMIN, 'somebody@example.com')->assertUnprocessable();

    $hashes = AuditTrail::all()->map(fn (stdClass $row): string => AuditTrail::metadata($row)['email_hash'])->all();

    expect($hashes[0])->toBe($hashes[1])
        ->and($hashes[2])->not->toBe($hashes[0]);
});

it('treats an account of the other realm as an unknown address', function (): void {
    $user = Browser::createAccount(Browser::PORTAL);

    (new Browser($this))->logIn(Browser::ADMIN, $user->email)->assertUnprocessable();

    $event = AuditTrail::last();

    expect($event->actor_type)->toBe('administrator')
        ->and($event->actor_id)->toBeNull()
        ->and($event->resource_id)->toBeNull()
        ->and(AuditTrail::metadata($event))->toHaveKey('email_hash');

    AuditTrail::assertHoldsNone($user->email, $user->id);
});

it('stores nothing of a value in the email field that is not an address', function (string $origin, string $prefix): void {
    $browser = new Browser($this);
    $browser->get($origin, $prefix.'/auth/csrf-cookie');

    // Somebody typed the password into the wrong field.
    $browser->post($origin, $prefix.'/auth/login', ['email' => 'hunter2-my-real-password', 'password' => 'x'])
        ->assertUnprocessable();

    expect(AuditTrail::all())->toBeEmpty();
})->with('realms');

it('records a logout', function (string $origin, string $prefix): void {
    $account = Browser::createAccount($origin);
    $browser = new Browser($this);
    $browser->logIn($origin, $account->email)->assertOk();

    $browser->post($origin, $prefix.'/auth/logout')->assertNoContent();

    $event = AuditTrail::last();

    expect(AuditTrail::actions())->toBe(['auth.login.succeeded', 'auth.logout'])
        ->and($event->actor_type)->toBe(accountType($origin))
        ->and($event->actor_id)->toBe($account->id)
        ->and($event->resource_id)->toBe($account->id)
        ->and(AuditTrail::metadata($event))->toBe([]);
})->with('realms');

it('stores no name, address or password of the account', function (string $origin, string $prefix): void {
    $account = Browser::createAccount($origin);
    $browser = new Browser($this);

    $browser->logIn($origin, $account->email, 'wrong-password')->assertUnprocessable();
    $browser->logIn($origin, $account->email)->assertOk();
    $browser->post($origin, $prefix.'/auth/logout')->assertNoContent();

    expect(AuditTrail::all())->toHaveCount(3);

    AuditTrail::assertHoldsNone($account->email, $account->name, Browser::PASSWORD, 'wrong-password', $account->password);
})->with('realms');

it('does not log in when the audit event cannot be written', function (string $origin, string $prefix): void {
    $account = Browser::createAccount($origin);
    $browser = new Browser($this);
    $this->app->instance(AuditLog::class, InMemoryAuditLog::failing());

    $browser->logIn($origin, $account->email)->assertStatus(500);

    $this->app->forgetInstance(AuditLog::class);

    $browser->get($origin, $prefix.'/auth/user')->assertUnauthorized();
    expect(AuditTrail::all())->toBeEmpty();
})->with('realms');

it('answers a failed login with an error when the audit event cannot be written', function (string $origin): void {
    $account = Browser::createAccount($origin);
    $this->app->instance(AuditLog::class, InMemoryAuditLog::failing());

    (new Browser($this))->logIn($origin, $account->email, 'wrong-password')->assertStatus(500);
})->with('realms');

it('does not log out when the audit event cannot be written', function (string $origin, string $prefix): void {
    $account = Browser::createAccount($origin);
    $browser = new Browser($this);
    $browser->logIn($origin, $account->email)->assertOk();

    $this->app->instance(AuditLog::class, InMemoryAuditLog::failing());
    $browser->post($origin, $prefix.'/auth/logout')->assertStatus(500);
    $this->app->forgetInstance(AuditLog::class);

    // Still logged in: an administrator who has not enrolled gets the 403
    // of the second-factor gate, a customer gets their account.
    expect($browser->get($origin, $prefix.'/auth/user')->getStatusCode())->not->toBe(401);

    $browser->post($origin, $prefix.'/auth/logout')->assertNoContent();
    $browser->get($origin, $prefix.'/auth/user')->assertUnauthorized();
})->with('realms');

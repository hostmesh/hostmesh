<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Domain\Audit\AuditLog;
use App\Domain\Identity\Administrator;
use App\Domain\Identity\User;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\NotPwnedVerifier;
use Tests\Support\AuditTrail;
use Tests\Support\Browser;
use Tests\Support\InMemoryAuditLog;

// Issue #133, decision 0023: an account changes its own password with the
// current one, and with a code from its authenticator whenever two-factor
// is on (always in admin), like the other account actions. The new
// password has 15 characters to 72 bytes and is not a known breached one.
// The account's other sessions end; this one goes on.

uses(RefreshDatabase::class);

const NEW_PASSWORD = 'a correct horse battery staple';

beforeEach(function (): void {
    $this->freezeTime();
    // Pwned Passwords knows none of the test passwords unless a test says so.
    $this->pwnedBody = '';
    $this->pwnedStatus = 200;
    Http::fake(['api.pwnedpasswords.com/*' => fn () => Http::response($this->pwnedBody, $this->pwnedStatus)]);
});

/** A fully signed-in browser, moved to the next time step so a fresh code is valid. */
function signedInForPassword(mixed $test, string $origin, bool $enrolled = true): array
{
    $account = $enrolled ? Browser::createEnrolled($origin) : Browser::createAccount($origin);
    $browser = new Browser($test);
    $browser->signIn($origin, $account);

    $test->travel(30)->seconds();

    return [$account, $browser];
}

/** @param  array<string, string>  $overrides */
function passwordChange(array $overrides = []): array
{
    return [
        'current_password' => Browser::PASSWORD,
        'new_password' => NEW_PASSWORD,
        'code' => Browser::code(),
        ...$overrides,
    ];
}

it('changes the password with the current one and a code', function (string $origin, string $prefix): void {
    [$account, $browser] = signedInForPassword($this, $origin);

    $browser->post($origin, $prefix.'/auth/password', passwordChange())->assertNoContent();

    expect(Hash::check(NEW_PASSWORD, $account->fresh()->password))->toBeTrue();
})->with('realms');

// Sanctum ends a session whose stored password hash no longer matches.
// The session that changed the password must carry the new one.
it('keeps the session that changed the password', function (string $origin, string $prefix): void {
    [, $browser] = signedInForPassword($this, $origin);

    $browser->post($origin, $prefix.'/auth/password', passwordChange())->assertNoContent();

    $browser->get($origin, $prefix.'/auth/user')->assertOk();
    $browser->get($origin, $prefix.'/auth/user')->assertOk();
})->with('realms');

it('ends the other sessions of the account, and no one else\'s', function (string $origin, string $prefix, string $sessionCookie): void {
    [$account, $browser] = signedInForPassword($this, $origin);
    $other = new Browser($this);
    $other->signIn($origin, $account);
    $this->travel(30)->seconds();
    $stranger = new Browser($this);
    $strangerAccount = Browser::createAccount($origin);
    $stranger->logIn($origin, $strangerAccount->email)->assertOk();

    $browser->post($origin, $prefix.'/auth/password', passwordChange())->assertNoContent();

    $table = $origin === Browser::ADMIN ? 'admin_sessions' : 'portal_sessions';

    expect(DB::table($table)->where('id', $other->sessionId($sessionCookie))->exists())->toBeFalse()
        ->and(DB::table($table)->where('id', $browser->sessionId($sessionCookie))->exists())->toBeTrue();

    $other->get($origin, $prefix.'/auth/user')->assertUnauthorized();
    expect(DB::table($table)->where('id', $stranger->sessionId($sessionCookie))->exists())->toBeTrue();
})->with('realms');

it('lets the new password log in, and not the old one', function (string $origin, string $prefix): void {
    [$account, $browser] = signedInForPassword($this, $origin, enrolled: $origin === Browser::PORTAL ? false : true);

    $browser->post($origin, $prefix.'/auth/password', passwordChange())->assertNoContent();

    (new Browser($this))->logIn($origin, $account->email, NEW_PASSWORD)->assertOk();
    (new Browser($this))->logIn($origin, $account->email)->assertUnprocessable();
})->with('realms');

it('asks a customer without two-factor for no code', function (): void {
    [$account, $browser] = signedInForPassword($this, Browser::PORTAL, enrolled: false);

    $browser->post(Browser::PORTAL, '/api/v1/customer/auth/password', passwordChange(['code' => '']))->assertNoContent();

    expect(Hash::check(NEW_PASSWORD, $account->fresh()->password))->toBeTrue();
});

it('refuses without the current password, and changes nothing', function (string $origin, string $prefix, string $sessionCookie, string $csrfCookie, int $lifetime, array $body): void {
    [$account, $browser] = signedInForPassword($this, $origin);

    $browser->post($origin, $prefix.'/auth/password', $body)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['current_password']);

    expect(Hash::check(Browser::PASSWORD, $account->fresh()->password))->toBeTrue()
        ->and(AuditTrail::actions())->not->toContain('auth.password_changed');
})->with('realms')->with([
    'wrong password' => [['current_password' => 'wrong-password', 'new_password' => NEW_PASSWORD]],
    'no password' => [['new_password' => NEW_PASSWORD]],
]);

it('refuses without a valid code where two-factor is on, and changes nothing', function (string $origin, string $prefix): void {
    [$account, $browser] = signedInForPassword($this, $origin);

    $browser->post($origin, $prefix.'/auth/password', passwordChange(['code' => Browser::wrongCode()]))
        ->assertUnprocessable()->assertJsonValidationErrors(['code']);
    $browser->post($origin, $prefix.'/auth/password', passwordChange(['code' => '']))
        ->assertUnprocessable()->assertJsonValidationErrors(['code']);

    expect(Hash::check(Browser::PASSWORD, $account->fresh()->password))->toBeTrue();
})->with('realms');

it('does not spend the code when the current password is wrong', function (string $origin, string $prefix): void {
    [, $browser] = signedInForPassword($this, $origin);
    $code = Browser::code();

    $browser->post($origin, $prefix.'/auth/password', passwordChange(['current_password' => 'wrong-password', 'code' => $code]))
        ->assertUnprocessable();
    $browser->post($origin, $prefix.'/auth/password', passwordChange(['code' => $code]))
        ->assertNoContent();
})->with('realms');

describe('the new password', function (): void {
    it('refuses one that is too short or too long, and changes nothing', function (string $origin, string $prefix, string $sessionCookie, string $csrfCookie, int $lifetime, string $password): void {
        [$account, $browser] = signedInForPassword($this, $origin);

        $browser->post($origin, $prefix.'/auth/password', passwordChange(['new_password' => $password]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['new_password']);

        expect(Hash::check(Browser::PASSWORD, $account->fresh()->password))->toBeTrue();
    })->with('realms')->with([
        '14 characters' => [str_repeat('a', 14)],
        '73 bytes' => [str_repeat('a', 73)],
        // 25 characters, but 75 bytes: bcrypt reads 72.
        '75 bytes of multibyte characters' => [str_repeat('€', 25)],
        'none' => [''],
    ]);

    it('takes 15 characters, and 72 bytes', function (string $password): void {
        [$account, $browser] = signedInForPassword($this, Browser::ADMIN);

        $browser->post(Browser::ADMIN, '/api/v1/admin/auth/password', passwordChange(['new_password' => $password]))
            ->assertNoContent();

        expect(Hash::check($password, $account->fresh()->password))->toBeTrue();
    })->with([
        '15 characters' => [str_repeat('b', 15)],
        '72 bytes' => [str_repeat('c', 72)],
        'any characters, no rule about them' => ['ünïcödé passwörd ✓'],
    ]);

    it('refuses a password that is known from breaches', function (): void {
        $hash = strtoupper(sha1(NEW_PASSWORD));
        $this->pwnedBody = substr($hash, 5).":42\r\n";
        [$account, $browser] = signedInForPassword($this, Browser::ADMIN);

        $browser->post(Browser::ADMIN, '/api/v1/admin/auth/password', passwordChange())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['new_password']);

        expect(Hash::check(Browser::PASSWORD, $account->fresh()->password))->toBeTrue();
        // Only the first five characters of the hash leave the server.
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.pwnedpasswords.com/range/'.substr($hash, 0, 5));
    });

    it('accepts the password when the breach check cannot be reached', function (): void {
        $this->pwnedStatus = 503;
        [$account, $browser] = signedInForPassword($this, Browser::ADMIN);

        $browser->post(Browser::ADMIN, '/api/v1/admin/auth/password', passwordChange())->assertNoContent();

        expect(Hash::check(NEW_PASSWORD, $account->fresh()->password))->toBeTrue();
    });

    // Laravel waits 30 seconds by default; a password change should not.
    it('gives up on the breach check after 5 seconds', function (): void {
        $verifier = app(UncompromisedVerifier::class);

        expect($verifier)->toBeInstanceOf(NotPwnedVerifier::class)
            ->and((new ReflectionProperty(NotPwnedVerifier::class, 'timeout'))->getValue($verifier))->toBe(5);
    });

    it('asks no one when the breach check is switched off', function (): void {
        config(['hostmesh.passwords.breach_check' => false]);
        [, $browser] = signedInForPassword($this, Browser::ADMIN);

        $browser->post(Browser::ADMIN, '/api/v1/admin/auth/password', passwordChange())->assertNoContent();

        Http::assertNothingSent();
    });
});

it('records the change, by the account itself, with no secret', function (string $origin, string $prefix): void {
    [$account, $browser] = signedInForPassword($this, $origin);

    $browser->post($origin, $prefix.'/auth/password', passwordChange())->assertNoContent();

    $event = AuditTrail::all()->last();
    $type = $account instanceof Administrator ? 'administrator' : 'user';

    expect($event->action)->toBe('auth.password_changed')
        ->and($event->actor_type)->toBe($type)
        ->and($event->actor_id)->toBe($account->id)
        ->and($event->resource)->toBe($type)
        ->and($event->resource_id)->toBe($account->id)
        ->and(AuditTrail::metadata($event))->toBe([]);

    AuditTrail::assertHoldsNone(NEW_PASSWORD, Browser::PASSWORD, $account->password, $account->fresh()->password);
})->with('realms');

it('changes nothing when the audit log cannot be written', function (string $origin, string $prefix): void {
    [$account, $browser] = signedInForPassword($this, $origin);
    $this->app->instance(AuditLog::class, InMemoryAuditLog::failing());

    $browser->post($origin, $prefix.'/auth/password', passwordChange())->assertServerError();

    expect(Hash::check(Browser::PASSWORD, $account->fresh()->password))->toBeTrue();
})->with('realms');

it('allows 5 attempts a minute for one account', function (string $origin, string $prefix): void {
    [, $browser] = signedInForPassword($this, $origin);

    foreach (range(1, 5) as $attempt) {
        $browser->post($origin, $prefix.'/auth/password', passwordChange(['current_password' => 'wrong-password']))
            ->assertUnprocessable();
    }

    $browser->post($origin, $prefix.'/auth/password', passwordChange())->assertTooManyRequests();
})->with('realms');

it('needs a session', function (string $origin, string $prefix): void {
    $browser = new Browser($this);
    $browser->get($origin, $prefix.'/auth/csrf-cookie');

    $browser->post($origin, $prefix.'/auth/password', passwordChange())->assertUnauthorized();
})->with('realms');

it('is not mistaken for another realm', function (): void {
    [, $browser] = signedInForPassword($this, Browser::PORTAL);

    expect(User::query()->count())->toBe(1);

    $browser->get(Browser::ADMIN, '/api/v1/admin/auth/csrf-cookie');
    $browser->post(Browser::ADMIN, '/api/v1/admin/auth/password', passwordChange())->assertUnauthorized();
});

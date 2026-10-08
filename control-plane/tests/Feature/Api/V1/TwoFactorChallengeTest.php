<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Database\Factories\Concerns\TwoFactorTestValues;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Browser;

// Issue #25: the second step of a login, in both realms.

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeTime();
});

it('tells the client at login which step comes next', function (): void {
    $cases = [
        [Browser::ADMIN, Browser::createEnrolled(Browser::ADMIN), 'challenge'],
        [Browser::ADMIN, Browser::createAccount(Browser::ADMIN), 'enrollment'],
        [Browser::PORTAL, Browser::createEnrolled(Browser::PORTAL), 'challenge'],
        [Browser::PORTAL, Browser::createAccount(Browser::PORTAL), 'none'],
    ];

    foreach ($cases as [$origin, $account, $step]) {
        (new Browser($this))->logIn($origin, $account->email)
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'id' => $account->id,
                    'name' => $account->name,
                    'email' => $account->email,
                    'two_factor_enabled' => $step === 'challenge',
                    'abilities' => ['view' => true, 'update' => $origin === Browser::PORTAL, 'manage_two_factor' => true],
                ],
                'meta' => ['second_factor' => $step],
            ]);
    }
});

it('completes the challenge with a valid code and issues a new session ID', function (
    string $origin, string $prefix, string $sessionCookie,
): void {
    $account = Browser::createEnrolled($origin);
    $browser = new Browser($this);
    $browser->logIn($origin, $account->email)->assertOk();
    $before = $browser->sessionId($sessionCookie);

    $browser->post($origin, $prefix.'/auth/two-factor/challenge', ['code' => Browser::code()])
        ->assertNoContent();

    expect($browser->sessionId($sessionCookie))->not->toBe($before);
})->with('realms');

it('rejects an invalid code', function (string $origin, string $prefix): void {
    $account = Browser::createEnrolled($origin);
    $browser = new Browser($this);
    $browser->logIn($origin, $account->email)->assertOk();

    $browser->post($origin, $prefix.'/auth/two-factor/challenge', ['code' => Browser::wrongCode()])
        ->assertUnprocessable()
        ->assertExactJson([
            'message' => 'The two-factor code is invalid.',
            'errors' => ['code' => ['The two-factor code is invalid.']],
        ]);
})->with('realms');

it('rejects a code that is reused within its time window', function (string $origin, string $prefix): void {
    $account = Browser::createEnrolled($origin);
    $code = Browser::code();

    $first = new Browser($this);
    $first->logIn($origin, $account->email)->assertOk();
    $first->post($origin, $prefix.'/auth/two-factor/challenge', ['code' => $code])->assertNoContent();

    // Somebody who saw the code tries it from another browser, 10 seconds later.
    $this->travel(10)->seconds();

    $second = new Browser($this);
    $second->logIn($origin, $account->email)->assertOk();
    $second->post($origin, $prefix.'/auth/two-factor/challenge', ['code' => $code])->assertUnprocessable();

    // The next code works.
    $this->travel(30)->seconds();
    $second->post($origin, $prefix.'/auth/two-factor/challenge', ['code' => Browser::code()])->assertNoContent();
})->with('realms');

it('rejects the code that confirmed enrollment when it is replayed', function (string $origin, string $prefix): void {
    $account = Browser::createAccount($origin);
    $browser = new Browser($this);
    $browser->logIn($origin, $account->email)->assertOk();
    $secret = $browser->post($origin, $prefix.'/auth/two-factor')->json('data.secret');
    $code = Browser::code($secret);
    $browser->post($origin, $prefix.'/auth/two-factor/confirm', ['code' => $code])->assertOk();

    $other = new Browser($this);
    $other->logIn($origin, $account->email)->assertOk();
    $other->post($origin, $prefix.'/auth/two-factor/challenge', ['code' => $code])->assertUnprocessable();
})->with('realms');

it('accepts a recovery code exactly once', function (string $origin, string $prefix): void {
    $account = Browser::createEnrolled($origin);

    $first = new Browser($this);
    $first->logIn($origin, $account->email)->assertOk();
    $first->post($origin, $prefix.'/auth/two-factor/challenge', ['recovery_code' => 'aaaaa bbbbb ccccc ddddd'])
        ->assertNoContent();

    expect($account->fresh()->two_factor_recovery_codes)->toBe([]);

    $second = new Browser($this);
    $second->logIn($origin, $account->email)->assertOk();
    $second->post($origin, $prefix.'/auth/two-factor/challenge', ['recovery_code' => TwoFactorTestValues::TEST_RECOVERY_CODE])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['recovery_code']);
})->with('realms');

it('rejects an unknown recovery code', function (string $origin, string $prefix): void {
    $account = Browser::createEnrolled($origin);
    $browser = new Browser($this);
    $browser->logIn($origin, $account->email)->assertOk();

    $browser->post($origin, $prefix.'/auth/two-factor/challenge', ['recovery_code' => 'ZZZZZ-ZZZZZ-ZZZZZ-ZZZZZ'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['recovery_code']);

    expect($account->fresh()->two_factor_recovery_codes)->toHaveCount(1);
})->with('realms');

it('wants exactly one of code and recovery code', function (string $origin, string $prefix): void {
    $account = Browser::createEnrolled($origin);
    $browser = new Browser($this);
    $browser->logIn($origin, $account->email)->assertOk();

    $browser->post($origin, $prefix.'/auth/two-factor/challenge')
        ->assertUnprocessable()->assertJsonValidationErrors(['code', 'recovery_code']);
    $browser->post($origin, $prefix.'/auth/two-factor/challenge', [
        'code' => Browser::code(), 'recovery_code' => TwoFactorTestValues::TEST_RECOVERY_CODE,
    ])->assertUnprocessable()->assertJsonValidationErrors(['code', 'recovery_code']);
})->with('realms');

it('refuses a challenge for an account without two-factor', function (string $origin, string $prefix): void {
    $account = Browser::createAccount($origin);
    $browser = new Browser($this);
    $browser->logIn($origin, $account->email)->assertOk();

    $browser->post($origin, $prefix.'/auth/two-factor/challenge', ['code' => Browser::code()])
        ->assertUnprocessable();
})->with('realms');

it('requires a password session', function (string $origin, string $prefix): void {
    $browser = new Browser($this);
    $browser->get($origin, $prefix.'/auth/csrf-cookie');

    $browser->post($origin, $prefix.'/auth/two-factor/challenge', ['code' => '123456'])->assertUnauthorized();
})->with('realms');

it('rate limits the challenge for one account', function (string $origin, string $prefix): void {
    $account = Browser::createEnrolled($origin);
    $browser = new Browser($this);
    $browser->logIn($origin, $account->email)->assertOk();

    foreach (range(1, 5) as $ignored) {
        $browser->post($origin, $prefix.'/auth/two-factor/challenge', ['code' => Browser::wrongCode()])
            ->assertUnprocessable();
    }

    // Even the right code is refused now.
    $browser->post($origin, $prefix.'/auth/two-factor/challenge', ['code' => Browser::code()])
        ->assertTooManyRequests()
        ->assertHeader('Retry-After');

    // A new login from another browser does not reset the count.
    $other = new Browser($this);
    $other->logIn($origin, $account->email)->assertOk();
    $other->post($origin, $prefix.'/auth/two-factor/challenge', ['code' => Browser::code()])
        ->assertTooManyRequests();

    $this->travel(61)->seconds();

    $browser->post($origin, $prefix.'/auth/two-factor/challenge', ['code' => Browser::code()])
        ->assertNoContent();
})->with('realms');

it('counts the challenge limit per account', function (string $origin, string $prefix): void {
    $blocked = Browser::createEnrolled($origin);
    $browser = new Browser($this);
    $browser->logIn($origin, $blocked->email)->assertOk();

    foreach (range(1, 6) as $ignored) {
        $browser->post($origin, $prefix.'/auth/two-factor/challenge', ['code' => Browser::wrongCode()]);
    }

    $other = Browser::createEnrolled($origin);
    $otherBrowser = new Browser($this);
    $otherBrowser->logIn($origin, $other->email)->assertOk();
    $otherBrowser->post($origin, $prefix.'/auth/two-factor/challenge', ['code' => Browser::code()])
        ->assertNoContent();
})->with('realms');

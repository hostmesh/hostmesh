<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Domain\Identity\TwoFactor\RecoveryCodes;
use Database\Factories\Concerns\TwoFactorTestValues;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Browser;

// Issue #25: turning two-factor off and replacing the recovery codes. Both
// need the current password and a valid code.

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeTime();
});

/** A fully signed-in browser, moved to the next time step so a fresh code is valid. */
function signedIn(mixed $test, string $origin): array
{
    $account = Browser::createEnrolled($origin);
    $browser = new Browser($test);
    $browser->signIn($origin, $account);

    $test->travel(30)->seconds();

    return [$account, $browser];
}

it('disables two-factor with the current password and a valid code', function (string $origin, string $prefix): void {
    [$account, $browser] = signedIn($this, $origin);

    $browser->post($origin, $prefix.'/auth/two-factor/disable', ['password' => Browser::PASSWORD, 'code' => Browser::code()])
        ->assertNoContent();

    $fresh = $account->fresh();

    expect($fresh->hasTwoFactor())->toBeFalse()
        ->and($fresh->two_factor_secret)->toBeNull()
        ->and($fresh->two_factor_recovery_codes)->toBeNull();
})->with('realms');

// Issue #212: a DELETE has no documented body, so the password went into the
// query string. Disabling is a POST now, and the DELETE is gone.
it('does not disable two-factor with a DELETE', function (string $origin, string $prefix): void {
    [$account, $browser] = signedIn($this, $origin);

    $browser->delete($origin, $prefix.'/auth/two-factor', ['password' => Browser::PASSWORD, 'code' => Browser::code()])
        ->assertMethodNotAllowed();

    expect($account->fresh()->hasTwoFactor())->toBeTrue();
})->with('realms');

it('does not disable two-factor without the current password', function (string $origin, string $prefix, string $sessionCookie, string $csrfCookie, int $lifetime, array $body): void {
    [$account, $browser] = signedIn($this, $origin);

    $browser->post($origin, $prefix.'/auth/two-factor/disable', $body + ['code' => Browser::code()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['password']);

    expect($account->fresh()->hasTwoFactor())->toBeTrue();
})->with('realms')->with([
    'wrong password' => [['password' => 'wrong-password']],
    'no password' => [[]],
]);

it('does not disable two-factor without a valid code', function (string $origin, string $prefix): void {
    [$account, $browser] = signedIn($this, $origin);

    $browser->post($origin, $prefix.'/auth/two-factor/disable', ['password' => Browser::PASSWORD, 'code' => Browser::wrongCode()])
        ->assertUnprocessable()->assertJsonValidationErrors(['code']);
    $browser->post($origin, $prefix.'/auth/two-factor/disable', ['password' => Browser::PASSWORD])
        ->assertUnprocessable()->assertJsonValidationErrors(['code']);

    expect($account->fresh()->hasTwoFactor())->toBeTrue();
})->with('realms');

it('does not accept the code of the login again to disable two-factor', function (string $origin, string $prefix): void {
    $account = Browser::createEnrolled($origin);
    $browser = new Browser($this);
    $code = Browser::code();
    $browser->logIn($origin, $account->email)->assertOk();
    $browser->post($origin, $prefix.'/auth/two-factor/challenge', ['code' => $code])->assertNoContent();

    $browser->post($origin, $prefix.'/auth/two-factor/disable', ['password' => Browser::PASSWORD, 'code' => $code])
        ->assertUnprocessable()->assertJsonValidationErrors(['code']);

    expect($account->fresh()->hasTwoFactor())->toBeTrue();
})->with('realms');

it('does not spend the code when the password is wrong', function (string $origin, string $prefix): void {
    [, $browser] = signedIn($this, $origin);
    $code = Browser::code();

    $browser->post($origin, $prefix.'/auth/two-factor/disable', ['password' => 'wrong-password', 'code' => $code])
        ->assertUnprocessable();
    $browser->post($origin, $prefix.'/auth/two-factor/disable', ['password' => Browser::PASSWORD, 'code' => $code])
        ->assertNoContent();
})->with('realms');

it('sends an administrator back to enrollment after disabling, and nowhere else', function (): void {
    [, $browser] = signedIn($this, Browser::ADMIN);

    $browser->post(Browser::ADMIN, '/api/v1/admin/auth/two-factor/disable', ['password' => Browser::PASSWORD, 'code' => Browser::code()])
        ->assertNoContent();

    $browser->get(Browser::ADMIN, '/api/v1/admin/auth/user')
        ->assertForbidden()->assertJsonPath('code', 'two_factor_enrollment_required');

    $secret = $browser->post(Browser::ADMIN, '/api/v1/admin/auth/two-factor')->assertOk()->json('data.secret');

    $this->travel(30)->seconds();
    $browser->post(Browser::ADMIN, '/api/v1/admin/auth/two-factor/confirm', ['code' => Browser::code($secret)])->assertOk();
    $browser->get(Browser::ADMIN, '/api/v1/admin/auth/user')->assertOk();
});

it('leaves a customer signed in without two-factor after disabling', function (): void {
    [, $browser] = signedIn($this, Browser::PORTAL);

    $browser->post(Browser::PORTAL, '/api/v1/customer/auth/two-factor/disable', ['password' => Browser::PASSWORD, 'code' => Browser::code()])
        ->assertNoContent();

    $browser->get(Browser::PORTAL, '/api/v1/customer/auth/user')
        ->assertOk()->assertJsonPath('data.two_factor_enabled', false);
});

it('replaces the recovery codes and returns the new ones once', function (string $origin, string $prefix): void {
    [$account, $browser] = signedIn($this, $origin);

    $codes = $browser->post($origin, $prefix.'/auth/two-factor/recovery-codes', ['password' => Browser::PASSWORD, 'code' => Browser::code()])
        ->assertOk()
        ->json('data.recovery_codes');

    $recoveryCodes = new RecoveryCodes;

    expect($codes)->toHaveCount(10)
        ->and($codes)->not->toContain(TwoFactorTestValues::TEST_RECOVERY_CODE)
        ->and($account->fresh()->two_factor_recovery_codes)->toBe(array_map($recoveryCodes->hash(...), $codes));

    // The old code is dead, a new one works.
    $other = new Browser($this);
    $other->logIn($origin, $account->email)->assertOk();
    $other->post($origin, $prefix.'/auth/two-factor/challenge', ['recovery_code' => TwoFactorTestValues::TEST_RECOVERY_CODE])
        ->assertUnprocessable();
    $other->post($origin, $prefix.'/auth/two-factor/challenge', ['recovery_code' => $codes[0]])
        ->assertNoContent();
})->with('realms');

it('does not replace the recovery codes without the password and a valid code', function (string $origin, string $prefix): void {
    [$account, $browser] = signedIn($this, $origin);
    $before = $account->fresh()->two_factor_recovery_codes;

    $browser->post($origin, $prefix.'/auth/two-factor/recovery-codes', ['password' => 'wrong-password', 'code' => Browser::code()])
        ->assertUnprocessable()->assertJsonValidationErrors(['password']);
    $browser->post($origin, $prefix.'/auth/two-factor/recovery-codes', ['password' => Browser::PASSWORD, 'code' => Browser::wrongCode()])
        ->assertUnprocessable()->assertJsonValidationErrors(['code']);

    expect($account->fresh()->two_factor_recovery_codes)->toBe($before);
})->with('realms');

it('refuses both for an account without two-factor', function (): void {
    $account = Browser::createAccount(Browser::PORTAL);
    $browser = new Browser($this);
    $browser->logIn(Browser::PORTAL, $account->email)->assertOk();

    $body = ['password' => Browser::PASSWORD, 'code' => Browser::code()];

    $browser->post(Browser::PORTAL, '/api/v1/customer/auth/two-factor/disable', $body)
        ->assertUnprocessable()->assertJsonValidationErrors(['code']);
    $browser->post(Browser::PORTAL, '/api/v1/customer/auth/two-factor/recovery-codes', $body)
        ->assertUnprocessable()->assertJsonValidationErrors(['code']);
});

it('needs a completed second factor for both', function (string $origin, string $prefix): void {
    $account = Browser::createEnrolled($origin);
    $browser = new Browser($this);
    $browser->logIn($origin, $account->email)->assertOk();

    $body = ['password' => Browser::PASSWORD, 'code' => Browser::code()];

    $browser->post($origin, $prefix.'/auth/two-factor/disable', $body)->assertForbidden();
    $browser->post($origin, $prefix.'/auth/two-factor/recovery-codes', $body)->assertForbidden();

    expect($account->fresh()->hasTwoFactor())->toBeTrue();
})->with('realms');

<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Domain\Identity\TwoFactor\RecoveryCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Browser;

// Issue #25: enrolling an authenticator app, in both realms.

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeTime();
});

function enrolling(mixed $test, string $origin, string $prefix): array
{
    $account = Browser::createAccount($origin);
    $browser = new Browser($test);
    $browser->logIn($origin, $account->email)->assertOk();

    $secret = $browser->post($origin, $prefix.'/auth/two-factor')->assertOk()->json('data.secret');

    return [$account, $browser, $secret];
}

it('starts enrollment with a secret and a provisioning URI', function (string $origin, string $prefix): void {
    $account = Browser::createAccount($origin);
    $browser = new Browser($this);
    $browser->logIn($origin, $account->email)->assertOk();

    $response = $browser->post($origin, $prefix.'/auth/two-factor')
        ->assertOk()
        ->assertJsonStructure(['data' => ['secret', 'provisioning_uri']]);

    $secret = $response->json('data.secret');

    expect($secret)->toMatch('/^[A-Z2-7]{32}$/')
        ->and($response->json('data.provisioning_uri'))->toStartWith('otpauth://totp/')
        ->and($response->json('data.provisioning_uri'))->toContain('secret='.$secret)
        ->and(rawurldecode($response->json('data.provisioning_uri')))->toContain($account->email)
        ->and($account->fresh()->two_factor_secret)->toBe($secret)
        ->and($account->fresh()->hasTwoFactor())->toBeFalse();
})->with('realms');

it('keeps the secret encrypted in the database', function (string $origin, string $prefix): void {
    [$account, , $secret] = enrolling($this, $origin, $prefix);

    $stored = DB::table($account->getTable())->where('id', $account->id)->value('two_factor_secret');

    expect($stored)->not->toContain($secret);
})->with('realms');

it('confirms enrollment with a valid code and returns the recovery codes once', function (string $origin, string $prefix): void {
    [$account, $browser, $secret] = enrolling($this, $origin, $prefix);

    $codes = $browser->post($origin, $prefix.'/auth/two-factor/confirm', ['code' => Browser::code($secret)])
        ->assertOk()
        ->json('data.recovery_codes');

    $fresh = $account->fresh();

    expect($codes)->toHaveCount(10)
        ->and($fresh->hasTwoFactor())->toBeTrue()
        ->and($fresh->two_factor_recovery_codes)->toBe(array_map((new RecoveryCodes)->hash(...), $codes));

    $stored = DB::table($account->getTable())->where('id', $account->id)->value('two_factor_recovery_codes');

    foreach ($codes as $code) {
        expect($stored)->not->toContain($code);
    }
})->with('realms');

it('accepts the code with the space an authenticator app shows', function (string $origin, string $prefix): void {
    [, $browser, $secret] = enrolling($this, $origin, $prefix);
    $code = Browser::code($secret);

    $browser->post($origin, $prefix.'/auth/two-factor/confirm', ['code' => substr($code, 0, 3).' '.substr($code, 3)])
        ->assertOk();
})->with('realms');

it('rejects an invalid code and leaves two-factor off', function (string $origin, string $prefix): void {
    [$account, $browser, $secret] = enrolling($this, $origin, $prefix);

    $browser->post($origin, $prefix.'/auth/two-factor/confirm', ['code' => Browser::wrongCode($secret)])
        ->assertUnprocessable()
        ->assertExactJson([
            'message' => 'The two-factor code is invalid.',
            'errors' => ['code' => ['The two-factor code is invalid.']],
        ]);

    expect($account->fresh()->hasTwoFactor())->toBeFalse();
})->with('realms');

it('validates the code', function (string $origin, string $prefix): void {
    [, $browser] = enrolling($this, $origin, $prefix);

    $browser->post($origin, $prefix.'/auth/two-factor/confirm')
        ->assertUnprocessable()->assertJsonValidationErrors(['code']);
    $browser->post($origin, $prefix.'/auth/two-factor/confirm', ['code' => ['123456']])
        ->assertUnprocessable()->assertJsonValidationErrors(['code']);
})->with('realms');

it('refuses to confirm before enrollment was started', function (string $origin, string $prefix): void {
    $account = Browser::createAccount($origin);
    $browser = new Browser($this);
    $browser->logIn($origin, $account->email)->assertOk();

    $browser->post($origin, $prefix.'/auth/two-factor/confirm', ['code' => Browser::code()])
        ->assertUnprocessable()->assertJsonValidationErrors(['code']);
})->with('realms');

it('replaces the secret of an abandoned enrollment', function (string $origin, string $prefix): void {
    [$account, $browser, $first] = enrolling($this, $origin, $prefix);

    $second = $browser->post($origin, $prefix.'/auth/two-factor')->assertOk()->json('data.secret');

    expect($second)->not->toBe($first);

    $browser->post($origin, $prefix.'/auth/two-factor/confirm', ['code' => Browser::code($first)])
        ->assertUnprocessable();
    $browser->post($origin, $prefix.'/auth/two-factor/confirm', ['code' => Browser::code($second)])
        ->assertOk();

    expect($account->fresh()->two_factor_secret)->toBe($second);
})->with('realms');

it('requires a session', function (string $origin, string $prefix): void {
    $browser = new Browser($this);
    $browser->get($origin, $prefix.'/auth/csrf-cookie');

    $browser->post($origin, $prefix.'/auth/two-factor')->assertUnauthorized();
    $browser->post($origin, $prefix.'/auth/two-factor/confirm', ['code' => '123456'])->assertUnauthorized();
})->with('realms');

it('refuses enrollment with 409 when two-factor is already on', function (string $origin, string $prefix): void {
    $account = Browser::createEnrolled($origin);
    $browser = new Browser($this);
    $browser->signIn($origin, $account);

    $browser->post($origin, $prefix.'/auth/two-factor')->assertConflict();

    expect($account->fresh()->hasTwoFactor())->toBeTrue();
})->with('realms');

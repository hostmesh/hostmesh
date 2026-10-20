<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Domain\Audit\AuditLog;
use App\Domain\Identity\TwoFactor\RecoveryCodes;
use Database\Factories\Concerns\TwoFactorTestValues;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AuditTrail;
use Tests\Support\Browser;
use Tests\Support\InMemoryAuditLog;

// Issue #27, decision 0013: every change to an account's second factor is
// audited, and so is every challenge. No secret, code or recovery code is
// ever stored, and a change whose event cannot be written is rolled back.

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeTime();
});

/** A fully signed-in browser, moved to the next time step so a fresh code is valid. */
function signedInForAudit(mixed $test, string $origin): array
{
    $account = Browser::createEnrolled($origin);
    $browser = new Browser($test);
    $browser->signIn($origin, $account);

    $test->travel(30)->seconds();

    return [$account, $browser];
}

it('records enrollment and confirmation, and none of the secrets', function (string $origin, string $prefix): void {
    $account = Browser::createAccount($origin);
    $browser = new Browser($this);
    $browser->logIn($origin, $account->email)->assertOk();

    $secret = $browser->post($origin, $prefix.'/auth/two-factor')->assertOk()->json('data.secret');
    $codes = $browser->post($origin, $prefix.'/auth/two-factor/confirm', ['code' => Browser::code($secret)])
        ->assertOk()->json('data.recovery_codes');

    expect(AuditTrail::actions())->toBe([
        'auth.login.succeeded',
        'auth.two_factor.enrollment_started',
        'auth.two_factor.confirmed',
    ]);

    foreach (AuditTrail::all() as $event) {
        expect($event->actor_type)->toBe($origin === Browser::ADMIN ? 'administrator' : 'user')
            ->and($event->actor_id)->toBe($account->id)
            ->and($event->resource_id)->toBe($account->id)
            ->and(AuditTrail::metadata($event))->toBe([])
            ->and($event->ip_address)->toBe('127.0.0.1')
            ->and($event->request_id)->not->toBeNull();
    }

    AuditTrail::assertHoldsNone(
        $secret,
        $account->email,
        $account->name,
        ...$codes,
        ...array_map((new RecoveryCodes)->hash(...), $codes),
    );
})->with('realms');

it('records a challenge passed with a code', function (string $origin): void {
    $account = Browser::createEnrolled($origin);

    (new Browser($this))->signIn($origin, $account);

    $event = AuditTrail::last();

    expect(AuditTrail::actions())->toBe(['auth.login.succeeded', 'auth.two_factor.challenge_passed'])
        ->and($event->actor_id)->toBe($account->id)
        ->and($event->resource_id)->toBe($account->id)
        ->and(AuditTrail::metadata($event))->toBe([]);

    AuditTrail::assertHoldsNone(TwoFactorTestValues::TEST_SECRET);
})->with('realms');

it('records a refused challenge with the method that was tried', function (string $origin, string $prefix, string $sessionCookie, string $csrfCookie, int $lifetime, string $method): void {
    $account = Browser::createEnrolled($origin);
    $browser = new Browser($this);
    $browser->logIn($origin, $account->email)->assertOk();

    $body = $method === 'totp' ? ['code' => Browser::wrongCode()] : ['recovery_code' => 'ZZZZZ-ZZZZZ-ZZZZZ-ZZZZZ'];
    $browser->post($origin, $prefix.'/auth/two-factor/challenge', $body)->assertUnprocessable();

    $event = AuditTrail::last();

    expect(AuditTrail::actions())->toBe(['auth.login.succeeded', 'auth.two_factor.challenge_failed'])
        ->and($event->actor_id)->toBe($account->id)
        ->and(AuditTrail::metadata($event))->toBe(['method' => $method]);

    AuditTrail::assertHoldsNone('ZZZZZ-ZZZZZ-ZZZZZ-ZZZZZ', (new RecoveryCodes)->hash('ZZZZZ-ZZZZZ-ZZZZZ-ZZZZZ'));
})->with('realms')->with(['a code' => ['totp'], 'a recovery code' => ['recovery_code']]);

it('records the use of a recovery code and how many are left', function (string $origin, string $prefix): void {
    $account = Browser::createEnrolled($origin);
    $browser = new Browser($this);
    $browser->logIn($origin, $account->email)->assertOk();

    $browser->post($origin, $prefix.'/auth/two-factor/challenge', ['recovery_code' => TwoFactorTestValues::TEST_RECOVERY_CODE])
        ->assertNoContent();

    $event = AuditTrail::last();

    expect(AuditTrail::actions())->toBe(['auth.login.succeeded', 'auth.two_factor.recovery_code_used'])
        ->and($event->actor_id)->toBe($account->id)
        ->and(AuditTrail::metadata($event))->toBe(['remaining' => 0]);

    AuditTrail::assertHoldsNone(
        TwoFactorTestValues::TEST_RECOVERY_CODE,
        (new RecoveryCodes)->hash(TwoFactorTestValues::TEST_RECOVERY_CODE),
    );
})->with('realms');

it('records that two-factor was disabled', function (string $origin, string $prefix): void {
    [$account, $browser] = signedInForAudit($this, $origin);

    $browser->post($origin, $prefix.'/auth/two-factor/disable', ['password' => Browser::PASSWORD, 'code' => Browser::code()])
        ->assertNoContent();

    $event = AuditTrail::last();

    expect($event->action)->toBe('auth.two_factor.disabled')
        ->and($event->actor_id)->toBe($account->id)
        ->and(AuditTrail::metadata($event))->toBe([]);

    AuditTrail::assertHoldsNone(TwoFactorTestValues::TEST_SECRET, Browser::PASSWORD);
})->with('realms');

it('records that the recovery codes were replaced, and none of them', function (string $origin, string $prefix): void {
    [$account, $browser] = signedInForAudit($this, $origin);

    $codes = $browser->post($origin, $prefix.'/auth/two-factor/recovery-codes', ['password' => Browser::PASSWORD, 'code' => Browser::code()])
        ->assertOk()->json('data.recovery_codes');

    $event = AuditTrail::last();

    expect($event->action)->toBe('auth.two_factor.recovery_codes_regenerated')
        ->and($event->actor_id)->toBe($account->id)
        ->and(AuditTrail::metadata($event))->toBe([]);

    AuditTrail::assertHoldsNone(...$codes, ...array_map((new RecoveryCodes)->hash(...), $codes));
})->with('realms');

it('records nothing for a refused change', function (string $origin, string $prefix): void {
    [, $browser] = signedInForAudit($this, $origin);
    $before = AuditTrail::all()->count();

    $browser->post($origin, $prefix.'/auth/two-factor/disable', ['password' => 'wrong-password', 'code' => Browser::code()])
        ->assertUnprocessable();
    $browser->post($origin, $prefix.'/auth/two-factor')->assertStatus(409);

    expect(AuditTrail::all())->toHaveCount($before);
})->with('realms');

it('does not start enrollment when the audit event cannot be written', function (string $origin, string $prefix): void {
    $account = Browser::createAccount($origin);
    $browser = new Browser($this);
    $browser->logIn($origin, $account->email)->assertOk();
    $this->app->instance(AuditLog::class, InMemoryAuditLog::failing());

    $browser->post($origin, $prefix.'/auth/two-factor')->assertStatus(500);

    expect($account->fresh()->two_factor_secret)->toBeNull();
})->with('realms');

it('does not turn two-factor on when the audit event cannot be written', function (string $origin, string $prefix): void {
    $account = Browser::createAccount($origin);
    $browser = new Browser($this);
    $browser->logIn($origin, $account->email)->assertOk();
    $secret = $browser->post($origin, $prefix.'/auth/two-factor')->assertOk()->json('data.secret');
    $this->app->instance(AuditLog::class, InMemoryAuditLog::failing());

    $browser->post($origin, $prefix.'/auth/two-factor/confirm', ['code' => Browser::code($secret)])->assertStatus(500);

    $fresh = $account->fresh();

    expect($fresh->hasTwoFactor())->toBeFalse()
        ->and($fresh->two_factor_recovery_codes)->toBeNull()
        // The code was not spent either: it still confirms.
        ->and($fresh->two_factor_last_step)->toBeNull();
})->with('realms');

it('does not pass the challenge when the audit event cannot be written', function (string $origin, string $prefix, string $sessionCookie, string $csrfCookie, int $lifetime, string $method): void {
    $account = Browser::createEnrolled($origin);
    $browser = new Browser($this);
    $browser->logIn($origin, $account->email)->assertOk();
    $this->app->instance(AuditLog::class, InMemoryAuditLog::failing());

    $body = $method === 'totp' ? ['code' => Browser::code()] : ['recovery_code' => TwoFactorTestValues::TEST_RECOVERY_CODE];
    $browser->post($origin, $prefix.'/auth/two-factor/challenge', $body)->assertStatus(500);

    $this->app->forgetInstance(AuditLog::class);
    $fresh = $account->fresh();

    $browser->get($origin, $prefix.'/auth/user')->assertForbidden()->assertJsonPath('code', 'two_factor_required');

    // Neither the code nor the recovery code was spent.
    expect($fresh->two_factor_last_step)->toBeNull()
        ->and($fresh->two_factor_recovery_codes)->toHaveCount(1);
})->with('realms')->with(['a code' => ['totp'], 'a recovery code' => ['recovery_code']]);

it('does not turn two-factor off when the audit event cannot be written', function (string $origin, string $prefix): void {
    [$account, $browser] = signedInForAudit($this, $origin);
    $this->app->instance(AuditLog::class, InMemoryAuditLog::failing());

    $browser->post($origin, $prefix.'/auth/two-factor/disable', ['password' => Browser::PASSWORD, 'code' => Browser::code()])
        ->assertStatus(500);

    expect($account->fresh()->hasTwoFactor())->toBeTrue();
})->with('realms');

it('does not replace the recovery codes when the audit event cannot be written', function (string $origin, string $prefix): void {
    [$account, $browser] = signedInForAudit($this, $origin);
    $before = $account->fresh()->two_factor_recovery_codes;
    $this->app->instance(AuditLog::class, InMemoryAuditLog::failing());

    $browser->post($origin, $prefix.'/auth/two-factor/recovery-codes', ['password' => Browser::PASSWORD, 'code' => Browser::code()])
        ->assertStatus(500);

    expect($account->fresh()->two_factor_recovery_codes)->toBe($before);
})->with('realms');

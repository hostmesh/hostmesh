<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Application\Commands\ResetAdministratorTwoFactor;
use App\Application\Exceptions\AdministratorNotFound;
use App\Application\Exceptions\TwoFactorNotEnabled;
use App\Domain\Audit\AuditLog;
use App\Domain\Identity\Administrator;
use App\Domain\Identity\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AuditTrail;
use Tests\Support\Browser;
use Tests\Support\InMemoryAuditLog;

// Issue #136, spec section 28: an administrator who lost both the
// authenticator and the recovery codes is locked out, because the admin
// realm requires two-factor. An operator with shell access resets it.

uses(RefreshDatabase::class);

/** The admin sessions that belong to this administrator. */
function adminSessionsOf(Administrator $administrator): int
{
    return DB::table('admin_sessions')->where('user_id', $administrator->id)->count();
}

it('removes the secret and the recovery codes', function (): void {
    $administrator = Administrator::factory()->withTwoFactor()->create(['two_factor_last_step' => 12345]);

    app(ResetAdministratorTwoFactor::class)->handle($administrator->email);

    $administrator->refresh();

    expect($administrator->hasTwoFactor())->toBeFalse()
        ->and($administrator->two_factor_secret)->toBeNull()
        ->and($administrator->two_factor_last_step)->toBeNull()
        ->and($administrator->two_factor_recovery_codes)->toBeNull();
});

it('ends the sessions of that administrator and no others', function (): void {
    $administrator = Administrator::factory()->withTwoFactor()->create();
    $colleague = Administrator::factory()->withTwoFactor()->create();
    (new Browser($this))->signIn(Browser::ADMIN, $administrator);
    (new Browser($this))->signIn(Browser::ADMIN, $colleague);

    expect(adminSessionsOf($administrator))->toBe(1)
        ->and(adminSessionsOf($colleague))->toBe(1);

    app(ResetAdministratorTwoFactor::class)->handle($administrator->email);

    expect(adminSessionsOf($administrator))->toBe(0)
        ->and(adminSessionsOf($colleague))->toBe(1);
});

it('records the reset, with the system as actor and no personal data', function (): void {
    $administrator = Administrator::factory()->withTwoFactor()->create(['email' => 'ada@example.com']);

    app(ResetAdministratorTwoFactor::class)->handle('ada@example.com');

    $event = AuditTrail::last();

    expect(AuditTrail::actions())->toBe(['administrator.two_factor_reset'])
        ->and($event->actor_type)->toBe('system')
        ->and($event->actor_id)->toBeNull()
        ->and($event->resource)->toBe('administrator')
        ->and($event->resource_id)->toBe($administrator->id)
        ->and(AuditTrail::metadata($event))->toBe([]);

    AuditTrail::assertHoldsNone('ada@example.com');
});

it('finds the address in any letter case', function (): void {
    $administrator = Administrator::factory()->withTwoFactor()->create(['email' => 'ada@example.com']);

    app(ResetAdministratorTwoFactor::class)->handle('Ada@Example.COM');

    expect($administrator->refresh()->hasTwoFactor())->toBeFalse();
});

it('refuses an address no administrator has, and records nothing', function (): void {
    // A customer is not an administrator, even with the same address.
    $customer = User::factory()->withTwoFactor()->create(['email' => 'ada@example.com']);

    expect(fn () => app(ResetAdministratorTwoFactor::class)->handle('ada@example.com'))
        ->toThrow(AdministratorNotFound::class, 'ada@example.com');

    expect($customer->refresh()->hasTwoFactor())->toBeTrue()
        ->and(AuditTrail::all())->toBeEmpty();
});

it('refuses an administrator without a second factor, and changes nothing', function (): void {
    $administrator = Administrator::factory()->create(['email' => 'ada@example.com']);
    (new Browser($this))->logIn(Browser::ADMIN, $administrator->email)->assertOk();

    expect(fn () => app(ResetAdministratorTwoFactor::class)->handle('ada@example.com'))
        ->toThrow(TwoFactorNotEnabled::class, 'ada@example.com');

    expect(adminSessionsOf($administrator))->toBe(1)
        ->and(AuditTrail::actions())->toBe(['auth.login.succeeded']);
});

// Decision 0013: a change whose audit event cannot be written does not happen.
it('keeps the second factor and the sessions when the audit event cannot be written', function (): void {
    $administrator = Administrator::factory()->withTwoFactor()->create();
    (new Browser($this))->signIn(Browser::ADMIN, $administrator);
    $this->app->instance(AuditLog::class, InMemoryAuditLog::failing());

    expect(fn () => app(ResetAdministratorTwoFactor::class)->handle($administrator->email))
        ->toThrow(RuntimeException::class);

    expect($administrator->refresh()->hasTwoFactor())->toBeTrue()
        ->and(adminSessionsOf($administrator))->toBe(1);
});

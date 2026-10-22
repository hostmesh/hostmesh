<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Application\Commands\ResetPassword;
use App\Application\Exceptions\AdministratorNotFound;
use App\Application\Exceptions\CustomerNotFound;
use App\Domain\Audit\AuditLog;
use App\Domain\Identity\Administrator;
use App\Domain\Identity\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\AuditTrail;
use Tests\Support\Browser;
use Tests\Support\InMemoryAuditLog;

// Issue #133, decision 0023: a lost password is reset by an operator with
// shell access, in both realms, until an emailed link comes with the mail
// work of Slice 6. A new password is generated and shown once, and the
// account's sessions end.

uses(RefreshDatabase::class);

dataset('accounts', [
    'an administrator' => ['administrator', Browser::ADMIN, 'admin_sessions', 'administrator.password_reset'],
    'a customer' => ['customer', Browser::PORTAL, 'portal_sessions', 'user.password_reset'],
]);

function resetPassword(string $kind, string $email): string
{
    $reset = app(ResetPassword::class);

    return $kind === 'administrator' ? $reset->administrator($email) : $reset->customer($email);
}

it('sets a new generated password', function (string $kind, string $origin): void {
    $account = Browser::createAccount($origin);

    $password = resetPassword($kind, $account->email);

    expect(strlen($password))->toBe(40)
        ->and(Hash::check($password, $account->fresh()->password))->toBeTrue()
        ->and(Hash::check(Browser::PASSWORD, $account->fresh()->password))->toBeFalse();
})->with('accounts');

it('keeps two-factor as it was', function (string $kind, string $origin): void {
    $account = Browser::createEnrolled($origin);

    resetPassword($kind, $account->email);

    expect($account->fresh()->hasTwoFactor())->toBeTrue();
})->with('accounts');

it('ends the sessions of that account and no others', function (string $kind, string $origin, string $table): void {
    $account = Browser::createAccount($origin);
    $other = Browser::createAccount($origin);
    (new Browser($this))->logIn($origin, $account->email)->assertOk();
    (new Browser($this))->logIn($origin, $other->email)->assertOk();

    resetPassword($kind, $account->email);

    expect(DB::table($table)->where('user_id', $account->id)->count())->toBe(0)
        ->and(DB::table($table)->where('user_id', $other->id)->count())->toBe(1);
})->with('accounts');

it('records the reset, with the system as actor and no secret', function (string $kind, string $origin, string $table, string $action): void {
    $account = Browser::createAccount($origin);

    $password = resetPassword($kind, $account->email);

    $event = AuditTrail::last();

    expect($event->action)->toBe($action)
        ->and($event->actor_type)->toBe('system')
        ->and($event->actor_id)->toBeNull()
        ->and($event->resource_id)->toBe($account->id)
        ->and(AuditTrail::metadata($event))->toBe([]);

    AuditTrail::assertHoldsNone($password, $account->email);
})->with('accounts');

it('finds the address in any letter case', function (string $kind, string $origin): void {
    $account = Browser::createAccount($origin);

    $password = resetPassword($kind, mb_strtoupper($account->email));

    expect(Hash::check($password, $account->fresh()->password))->toBeTrue();
})->with('accounts');

it('refuses an address the realm does not have, and records nothing', function (): void {
    // The realms are separate: one's address is not the other's account.
    Administrator::factory()->create(['email' => 'ada@example.com']);
    User::factory()->create(['email' => 'grace@example.com']);

    expect(fn () => app(ResetPassword::class)->customer('ada@example.com'))
        ->toThrow(CustomerNotFound::class, 'ada@example.com')
        ->and(fn () => app(ResetPassword::class)->administrator('grace@example.com'))
        ->toThrow(AdministratorNotFound::class, 'grace@example.com');

    expect(AuditTrail::all())->toBeEmpty();
});

it('changes nothing when the audit log cannot be written', function (string $kind, string $origin, string $table): void {
    $account = Browser::createAccount($origin);
    (new Browser($this))->logIn($origin, $account->email)->assertOk();
    $this->app->instance(AuditLog::class, InMemoryAuditLog::failing());

    expect(fn () => resetPassword($kind, $account->email))->toThrow(RuntimeException::class);

    expect(Hash::check(Browser::PASSWORD, $account->fresh()->password))->toBeTrue()
        ->and(DB::table($table)->where('user_id', $account->id)->count())->toBe(1);
})->with('accounts');

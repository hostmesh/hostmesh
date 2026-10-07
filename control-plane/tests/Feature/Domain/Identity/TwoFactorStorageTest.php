<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Domain\Identity\Administrator;
use App\Domain\Identity\TwoFactor\RecoveryCodes;
use App\Domain\Identity\User;
use Database\Factories\Concerns\TwoFactorTestValues;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

// Issue #25: what an account stores for two-factor authentication.

uses(RefreshDatabase::class);

dataset('accounts', [
    'administrator' => [Administrator::class],
    'user' => [User::class],
]);

it('encrypts the TOTP secret at rest', function (string $model): void {
    $account = $model::factory()->withTwoFactor()->create();

    $stored = DB::table($account->getTable())->where('id', $account->id)->value('two_factor_secret');

    expect($stored)->toBeString()
        ->and($stored)->not->toContain(TwoFactorTestValues::TEST_SECRET)
        ->and(Crypt::decryptString($stored))->toBe(TwoFactorTestValues::TEST_SECRET)
        ->and($account->fresh()->two_factor_secret)->toBe(TwoFactorTestValues::TEST_SECRET);
})->with('accounts');

it('stores recovery codes as hashes only', function (string $model): void {
    $account = $model::factory()->withTwoFactor()->create();

    $stored = DB::table($account->getTable())->where('id', $account->id)->value('two_factor_recovery_codes');

    expect($stored)->not->toContain(TwoFactorTestValues::TEST_RECOVERY_CODE)
        ->and(json_decode($stored, true))->toBe([(new RecoveryCodes)->hash(TwoFactorTestValues::TEST_RECOVERY_CODE)]);
})->with('accounts');

it('never serializes the secret or the recovery codes', function (string $model): void {
    $serialized = $model::factory()->withTwoFactor()->create()->fresh()->toArray();

    expect($serialized)->not->toHaveKey('two_factor_secret')
        ->and($serialized)->not->toHaveKey('two_factor_recovery_codes');
})->with('accounts');

it('counts two-factor as enabled only once it is confirmed', function (string $model): void {
    $account = $model::factory()->create();
    expect($account->hasTwoFactor())->toBeFalse();

    $account->beginTwoFactorEnrollment(TwoFactorTestValues::TEST_SECRET);
    expect($account->fresh()->hasTwoFactor())->toBeFalse()
        ->and($account->fresh()->two_factor_secret)->toBe(TwoFactorTestValues::TEST_SECRET);

    $account->confirmTwoFactor(['hash-one']);
    expect($account->fresh()->hasTwoFactor())->toBeTrue()
        ->and($account->fresh()->two_factor_recovery_codes)->toBe(['hash-one']);
})->with('accounts');

it('consumes a recovery code once', function (string $model): void {
    $account = $model::factory()->withTwoFactor()->create();
    $hash = (new RecoveryCodes)->hash(TwoFactorTestValues::TEST_RECOVERY_CODE);

    expect($account->consumeRecoveryCode('not-a-stored-hash'))->toBeFalse()
        ->and($account->consumeRecoveryCode($hash))->toBeTrue()
        ->and($account->fresh()->two_factor_recovery_codes)->toBe([])
        ->and($account->fresh()->consumeRecoveryCode($hash))->toBeFalse();
})->with('accounts');

it('removes everything when two-factor is disabled', function (string $model): void {
    $account = $model::factory()->withTwoFactor()->create(['two_factor_last_step' => 5]);

    $account->disableTwoFactor();

    $fresh = $account->fresh();
    expect($fresh->hasTwoFactor())->toBeFalse()
        ->and($fresh->two_factor_secret)->toBeNull()
        ->and($fresh->two_factor_last_step)->toBeNull()
        ->and($fresh->two_factor_recovery_codes)->toBeNull();
})->with('accounts');

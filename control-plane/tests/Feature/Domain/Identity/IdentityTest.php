<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Domain\Identity\Administrator;
use App\Domain\Identity\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

dataset('identities', [
    'administrator' => [Administrator::class, 'administrators'],
    'user' => [User::class, 'users'],
]);

it('stores the account in its own table with a UUID key', function (string $model, string $table): void {
    $account = $model::factory()->create();

    expect($account->getTable())->toBe($table)
        ->and(Str::isUuid($account->id))->toBeTrue();

    $this->assertDatabaseHas($table, ['id' => $account->id]);
})->with('identities');

it('hashes the password with bcrypt', function (string $model): void {
    $account = $model::factory()->create(['password' => 'plain-text-password']);

    expect($account->password)->not->toBe('plain-text-password')
        ->and(password_get_info($account->password)['algoName'])->toBe('bcrypt')
        ->and(Hash::check('plain-text-password', $account->password))->toBeTrue();
})->with('identities');

it('never serialises the password', function (string $model): void {
    expect($model::factory()->create()->toArray())->not->toHaveKey('password');
})->with('identities');

it('stores the email in lower case', function (string $model): void {
    expect($model::factory()->create(['email' => 'Jane@Example.COM'])->email)->toBe('jane@example.com');
})->with('identities');

it('refuses a second account with the same email', function (string $model): void {
    $model::factory()->create(['email' => 'jane@example.com']);

    expect(fn () => $model::factory()->create(['email' => 'JANE@example.com']))
        ->toThrow(QueryException::class);
})->with('identities');

it('lets the same email exist as an administrator and as a user', function (): void {
    Administrator::factory()->create(['email' => 'jane@example.com']);
    User::factory()->create(['email' => 'jane@example.com']);

    expect(Administrator::query()->count())->toBe(1)
        ->and(User::query()->count())->toBe(1);
});

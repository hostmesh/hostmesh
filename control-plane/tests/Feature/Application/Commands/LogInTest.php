<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Application\Commands\LogIn;
use App\Application\Exceptions\InvalidCredentials;
use App\Domain\Identity\Administrator;
use App\Domain\Identity\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

it('logs the account in on the given guard and returns it', function (): void {
    $administrator = Administrator::factory()->create();

    $account = app(LogIn::class)->handle('admin', $administrator->email, 'correct-password');

    expect($account->is($administrator))->toBeTrue()
        ->and(Auth::guard('admin')->check())->toBeTrue()
        ->and(Auth::guard('customer')->check())->toBeFalse();
});

it('refuses a wrong password, an unknown email and an account of the other guard alike', function (
    string $guard, string $email, string $password,
): void {
    Administrator::factory()->create(['email' => 'admin@example.com']);
    User::factory()->create(['email' => 'user@example.com']);

    expect(fn () => app(LogIn::class)->handle($guard, $email, $password))
        ->toThrow(InvalidCredentials::class, 'These credentials do not match our records.')
        ->and(Auth::guard('admin')->check())->toBeFalse()
        ->and(Auth::guard('customer')->check())->toBeFalse();
})->with([
    'wrong password' => ['admin', 'admin@example.com', 'wrong-password'],
    'unknown email' => ['admin', 'nobody@example.com', 'correct-password'],
    'user on the admin guard' => ['admin', 'user@example.com', 'correct-password'],
    'administrator on the customer guard' => ['customer', 'admin@example.com', 'correct-password'],
]);

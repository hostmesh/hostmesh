<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Domain\Identity\Administrator;
use App\Domain\Identity\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Process;
use Tests\Support\AuditTrail;

// Issue #133, decision 0023: `administrator:reset-password` and
// `customer:reset-password`, for an operator with shell access. The new
// password is generated and shown once.

uses(RefreshDatabase::class);

dataset('reset commands', [
    'administrator' => ['administrator:reset-password', Administrator::class],
    'customer' => ['customer:reset-password', User::class],
]);

/** The password line of the output. */
function printedResetPassword(string $output): string
{
    preg_match('/^\s*Password\s*[.:\s]+(\S{40})\s*$/m', $output, $match);

    return $match[1] ?? '';
}

it('resets the password and prints the new one once', function (string $command, string $model): void {
    $account = $model::factory()->create(['email' => 'ada@example.com']);

    $code = Artisan::call($command, ['email' => 'ada@example.com']);
    $output = Artisan::output();
    $password = printedResetPassword($output);

    expect($code)->toBe(0)
        ->and($output)->toContain('ada@example.com', 'not be shown again', 'signed out')
        ->and($password)->not->toBe('')
        ->and(substr_count($output, $password))->toBe(1)
        ->and(Hash::check($password, $account->fresh()->password))->toBeTrue();
})->with('reset commands');

it('refuses an address the realm does not have', function (string $command): void {
    $code = Artisan::call($command, ['email' => 'nobody@example.com']);

    expect($code)->toBe(1)
        ->and(Artisan::output())->toContain('nobody@example.com')
        ->and(AuditTrail::all())->toBeEmpty();
})->with('reset commands');

it('takes nothing but the address', function (string $command): void {
    $definition = Artisan::all()[$command]->getDefinition();

    expect(array_keys($definition->getArguments()))->toBe(['email'])
        ->and($definition->getOptions())->not->toHaveKey('password');
})->with('reset commands');

it('is registered in production', function (): void {
    $result = Process::path(base_path())->env(['APP_ENV' => 'production'])->run([PHP_BINARY, 'artisan', 'list', '--raw']);

    expect($result->successful())->toBeTrue()
        ->and($result->output())->toContain('administrator:reset-password', 'customer:reset-password');
});

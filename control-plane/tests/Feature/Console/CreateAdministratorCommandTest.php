<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Domain\Authorization\Role;
use App\Domain\Identity\Administrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Tests\Support\AuditTrail;

// Issue #122: `php artisan administrator:create`, for an operator with
// shell access. The password is generated and shown once.

uses(RefreshDatabase::class);

/** @return array{int, string} the exit code and what the command printed */
function createAdministrator(string ...$arguments): array
{
    $code = Artisan::call('administrator:create', parseArguments($arguments));

    return [$code, Artisan::output()];
}

/**
 * @param  array<int, string>  $arguments
 * @return array<string, string>
 */
function parseArguments(array $arguments): array
{
    $parsed = [];

    foreach ($arguments as $argument) {
        if (str_starts_with($argument, '--')) {
            [$option, $value] = explode('=', $argument, 2);
            $parsed[$option] = $value;
        } else {
            $parsed['email'] = $argument;
        }
    }

    return $parsed;
}

/** The password line of the output: the last word of the line that names it. */
function printedPassword(string $output): string
{
    preg_match('/^\s*Password\s*[.:\s]+(\S{40})\s*$/m', $output, $match);

    return $match[1] ?? '';
}

it('creates an owner and prints the generated password', function (): void {
    [$code, $output] = createAdministrator('ada@example.com', '--name=Ada Lovelace');

    $administrator = Administrator::query()->where('email', 'ada@example.com')->sole();
    $password = printedPassword($output);

    expect($code)->toBe(0)
        ->and($administrator->name)->toBe('Ada Lovelace')
        ->and($administrator->roles())->toBe([Role::Owner])
        ->and($output)->toContain('ada@example.com', 'owner', 'not be shown again')
        ->and($password)->not->toBe('')
        ->and(Hash::check($password, $administrator->password))->toBeTrue()
        ->and(substr_count($output, $password))->toBe(1);
});

it('gives the role that is asked for', function (): void {
    [$code] = createAdministrator('grace@example.com', '--name=Grace Hopper', '--role=read-only');

    expect($code)->toBe(0)
        ->and(Administrator::query()->where('email', 'grace@example.com')->sole()->roles())->toBe([Role::ReadOnly]);
});

it('refuses what it cannot use, and creates nothing', function (array $arguments, string $message): void {
    [$code, $output] = createAdministrator(...$arguments);

    expect($code)->toBe(1)
        ->and($output)->toContain($message)
        ->and(Administrator::query()->count())->toBe(0)
        ->and(AuditTrail::all())->toBeEmpty();
})->with([
    'an address that is not one' => [['not-an-address', '--name=Ada'], 'email'],
    'an unknown role' => [['ada@example.com', '--name=Ada', '--role=root'], 'owner, read-only'],
    'no name' => [['ada@example.com'], 'name'],
    'an empty name' => [['ada@example.com', '--name=  '], 'name'],
    'a name that is too long' => [['ada@example.com', '--name='.str_repeat('a', 256)], 'name'],
]);

it('refuses an address that is taken', function (): void {
    Administrator::factory()->create(['email' => 'ada@example.com']);

    [$code, $output] = createAdministrator('ada@example.com', '--name=Ada');

    expect($code)->toBe(1)
        ->and($output)->toContain('ada@example.com', 'already')
        ->and(Administrator::query()->count())->toBe(1);
});

it('writes the password to no log', function (): void {
    $handler = new TestHandler;
    $logger = Log::channel()->getLogger();
    assert($logger instanceof Logger);
    $logger->pushHandler($handler);

    [, $output] = createAdministrator('ada@example.com', '--name=Ada');
    $password = printedPassword($output);

    expect($password)->not->toBe('');

    foreach ($handler->getRecords() as $record) {
        expect(json_encode([$record->message, $record->context]))->not->toContain($password);
    }

    AuditTrail::assertHoldsNone($password);
});

it('takes no password from the command line', function (): void {
    $definition = Artisan::all()['administrator:create']->getDefinition();

    expect(array_keys($definition->getArguments()))->toBe(['email'])
        ->and($definition->hasOption('password'))->toBeFalse();
});

// A real process: unlike the end-to-end seed, this command is for production.
it('is registered in production', function (): void {
    $result = Process::path(base_path())->env(['APP_ENV' => 'production'])->run([PHP_BINARY, 'artisan', 'list', '--raw']);

    expect($result->successful())->toBeTrue()
        ->and($result->output())->toContain('administrator:create');
});

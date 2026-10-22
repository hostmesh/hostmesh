<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Domain\Identity\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Tests\Support\AuditTrail;

// Issue #134, decision 0022: `php artisan customer:create`, for an operator
// with shell access. The password is generated and shown once.

uses(RefreshDatabase::class);

/**
 * @param  array<string, string>  $arguments
 * @return array{int, string} the exit code and what the command printed
 */
function createCustomer(array $arguments): array
{
    $code = Artisan::call('customer:create', $arguments);

    return [$code, Artisan::output()];
}

/** The password line of the output: the last word of the line that names it. */
function printedCustomerPassword(string $output): string
{
    preg_match('/^\s*Password\s*[.:\s]+(\S{40})\s*$/m', $output, $match);

    return $match[1] ?? '';
}

it('creates a customer and prints the generated password', function (): void {
    [$code, $output] = createCustomer(['email' => 'grace@example.com', '--name' => 'Grace Hopper']);

    $user = User::query()->where('email', 'grace@example.com')->sole();
    $password = printedCustomerPassword($output);

    expect($code)->toBe(0)
        ->and($user->name)->toBe('Grace Hopper')
        ->and($output)->toContain('grace@example.com', 'not be shown again')
        ->and($password)->not->toBe('')
        ->and(Hash::check($password, $user->password))->toBeTrue()
        ->and(substr_count($output, $password))->toBe(1);
});

it('refuses what it cannot use, and creates nothing', function (array $arguments, string $message): void {
    [$code, $output] = createCustomer($arguments);

    expect($code)->toBe(1)
        ->and($output)->toContain($message)
        ->and(User::query()->count())->toBe(0)
        ->and(AuditTrail::all())->toBeEmpty();
})->with([
    'an address that is not one' => [['email' => 'not-an-address', '--name' => 'Grace'], 'email'],
    'no name' => [['email' => 'grace@example.com'], 'name'],
    'an empty name' => [['email' => 'grace@example.com', '--name' => '  '], 'name'],
    'a name that is too long' => [['email' => 'grace@example.com', '--name' => str_repeat('a', 256)], 'name'],
]);

it('refuses an address that is taken', function (): void {
    User::factory()->create(['email' => 'grace@example.com']);

    [$code, $output] = createCustomer(['email' => 'grace@example.com', '--name' => 'Grace']);

    expect($code)->toBe(1)
        ->and($output)->toContain('grace@example.com', 'already')
        ->and(User::query()->count())->toBe(1);
});

it('writes the password to no log', function (): void {
    $handler = new TestHandler;
    $logger = Log::channel()->getLogger();
    assert($logger instanceof Logger);
    $logger->pushHandler($handler);

    [, $output] = createCustomer(['email' => 'grace@example.com', '--name' => 'Grace']);
    $password = printedCustomerPassword($output);

    expect($password)->not->toBe('');

    foreach ($handler->getRecords() as $record) {
        expect(json_encode([$record->message, $record->context]))->not->toContain($password);
    }

    AuditTrail::assertHoldsNone($password);
});

it('takes no password from the command line', function (): void {
    $definition = Artisan::all()['customer:create']->getDefinition();

    expect(array_keys($definition->getArguments()))->toBe(['email'])
        ->and($definition->hasOption('password'))->toBeFalse();
});

it('is registered in production', function (): void {
    $result = Process::path(base_path())->env(['APP_ENV' => 'production'])->run([PHP_BINARY, 'artisan', 'list', '--raw']);

    expect($result->successful())->toBeTrue()
        ->and($result->output())->toContain('customer:create');
});

<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Domain\Identity\Administrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Tests\Support\AuditTrail;
use Tests\Support\Browser;

// Issue #136: `php artisan administrator:reset-two-factor`, for an operator
// with shell access, when an administrator lost both the authenticator and
// the recovery codes.

uses(RefreshDatabase::class);

/** @return array{int, string} the exit code and what the command printed */
function resetTwoFactor(string $email): array
{
    $code = Artisan::call('administrator:reset-two-factor', ['email' => $email]);

    return [$code, Artisan::output()];
}

it('resets the second factor and says what happens next', function (): void {
    $administrator = Administrator::factory()->withTwoFactor()->create(['email' => 'ada@example.com']);

    [$code, $output] = resetTwoFactor('ada@example.com');

    expect($code)->toBe(0)
        ->and($administrator->refresh()->hasTwoFactor())->toBeFalse()
        ->and($output)->toContain('ada@example.com', 'sessions', 'next login')
        ->and(AuditTrail::actions())->toBe(['administrator.two_factor_reset']);
});

it('signs the administrator out, and the next login leads into enrollment', function (): void {
    $this->freezeTime();
    $administrator = Administrator::factory()->withTwoFactor()->create();
    $before = new Browser($this);
    $before->signIn(Browser::ADMIN, $administrator);
    $before->get(Browser::ADMIN, '/api/v1/admin/auth/user')->assertOk();

    resetTwoFactor($administrator->email);

    $before->get(Browser::ADMIN, '/api/v1/admin/auth/user')->assertUnauthorized();

    $after = new Browser($this);
    $after->logIn(Browser::ADMIN, $administrator->email)
        ->assertOk()->assertJsonPath('meta.second_factor', 'enrollment');
    $after->get(Browser::ADMIN, '/api/v1/admin/auth/user')
        ->assertForbidden()->assertJsonPath('code', 'two_factor_enrollment_required');
});

it('refuses an address no administrator has', function (): void {
    [$code, $output] = resetTwoFactor('nobody@example.com');

    expect($code)->toBe(1)
        ->and($output)->toContain('nobody@example.com')
        ->and(AuditTrail::all())->toBeEmpty();
});

it('refuses an administrator without a second factor', function (): void {
    Administrator::factory()->create(['email' => 'ada@example.com']);

    [$code, $output] = resetTwoFactor('ada@example.com');

    expect($code)->toBe(1)
        ->and($output)->toContain('ada@example.com', 'no second factor')
        ->and(AuditTrail::all())->toBeEmpty();
});

// A real process: like administrator:create, this command is for production.
it('is registered in production', function (): void {
    $result = Process::path(base_path())->env(['APP_ENV' => 'production'])->run([PHP_BINARY, 'artisan', 'list', '--raw']);

    expect($result->successful())->toBeTrue()
        ->and($result->output())->toContain('administrator:reset-two-factor');
});

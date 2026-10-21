<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Application\Queries\GetBranding;
use App\Console\EndToEnd\Fixtures;
use App\Domain\Authorization\Role;
use App\Domain\Branding\BrandingRecord;
use App\Domain\Identity\Administrator;
use App\Domain\Identity\TwoFactor\Totp;
use App\Domain\Identity\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Process;
use PragmaRX\Google2FA\Google2FA;

// Issue #32: the data of the Playwright exit test. The command exists for
// that suite only and must be impossible to run anywhere else.

uses(RefreshDatabase::class);

/** @return array<string, mixed> */
function seeded(): array
{
    expect(Artisan::call('e2e:seed'))->toBe(0);

    return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
}

it('prints the accounts and the branding as JSON', function (): void {
    $seed = seeded();

    expect(array_keys($seed))->toBe(['branding', 'administrators', 'customers'])
        ->and(array_keys($seed['administrators']))->toBe(Fixtures::ADMINISTRATORS)
        ->and(array_keys($seed['customers']))->toBe(Fixtures::CUSTOMERS)
        ->and($seed['branding'])->toBe(['product_name' => 'Hostmesh E2E', 'primary_light' => '#0b3d91', 'support_url' => 'https://support.e2e.hostmesh.test/'])
        ->and($seed['administrators']['login']['email'])->toBe('admin-login@e2e.hostmesh.test')
        ->and($seed['customers']['login']['email'])->toBe('customer-login@e2e.hostmesh.test');
});

it('creates administrators who are owners with confirmed two-factor', function (): void {
    $seed = seeded();

    foreach ($seed['administrators'] as $entry) {
        $administrator = Administrator::query()->where('email', $entry['email'])->sole();

        expect($administrator->name)->toBe($entry['name'])
            ->and(Hash::check($entry['password'], $administrator->password))->toBeTrue()
            ->and($administrator->hasTwoFactor())->toBeTrue()
            ->and($administrator->two_factor_secret)->toBe($entry['totp_secret'])
            ->and($administrator->roles())->toBe([Role::Owner]);
    }
});

it('gives every account a password and a secret of its own', function (): void {
    $seed = seeded();
    $accounts = [...array_values($seed['administrators']), ...array_values($seed['customers'])];

    expect(array_unique(array_column($accounts, 'password')))->toHaveCount(count($accounts))
        ->and(array_unique(array_column($seed['administrators'], 'totp_secret')))->toHaveCount(count($seed['administrators']));
});

it('prints a secret whose current code the API accepts', function (): void {
    $entry = seeded()['administrators']['login'];
    $administrator = Administrator::query()->where('email', $entry['email'])->sole();
    $code = (new Google2FA)->oathTotp($entry['totp_secret'], intdiv(now()->getTimestamp(), 30));

    expect($administrator->acceptTotpCode(app(Totp::class), $code))->toBeTrue();
});

it('creates customers without two-factor', function (): void {
    $seed = seeded();

    foreach ($seed['customers'] as $entry) {
        $user = User::query()->where('email', $entry['email'])->sole();

        expect(Hash::check($entry['password'], $user->password))->toBeTrue()
            ->and($user->hasTwoFactor())->toBeFalse();
    }
});

// Issue #139: the portal's account flow turns two-factor on for its
// customer. A run that stopped half-way must not leave it on for the next.
it('has a customer for the two-factor flow and turns its two-factor off when it runs again', function (): void {
    $entry = seeded()['customers']['two-factor'];
    $user = User::query()->where('email', $entry['email'])->sole();
    $user->beginTwoFactorEnrollment(app(Totp::class)->generateSecret());
    $user->confirmTwoFactor([]);

    seeded();

    expect($user->refresh()->hasTwoFactor())->toBeFalse()
        ->and($user->two_factor_secret)->toBeNull();
});

it('sets the branding the tests look for', function (): void {
    seeded();

    $branding = app(GetBranding::class)->handle();

    expect($branding->productName)->toBe('Hostmesh E2E');
    expect(BrandingRecord::current()?->primary_light)->toBe('#0b3d91');
    // Issue #228: one link for the portal's footer; the two others stay
    // unset, so the suite also sees that the footer leaves them out.
    expect($branding->supportUrl)->toBe('https://support.e2e.hostmesh.test/')
        ->and($branding->termsUrl)->toBeNull()
        ->and($branding->privacyUrl)->toBeNull();
});

it('converges when it runs again, with new credentials', function (): void {
    $first = seeded();
    $second = seeded();

    expect(Administrator::query()->count())->toBe(count(Fixtures::ADMINISTRATORS))
        ->and(User::query()->count())->toBe(count(Fixtures::CUSTOMERS))
        ->and($second['administrators']['login']['password'])->not->toBe($first['administrators']['login']['password']);
});

// Review focus 1: a code works once per account. A reseed must let the
// next run log in during the same 30 seconds.
it('resets the last accepted step when it runs again', function (): void {
    $entry = seeded()['administrators']['login'];
    $administrator = Administrator::query()->where('email', $entry['email'])->sole();
    $code = (new Google2FA)->oathTotp($entry['totp_secret'], intdiv(now()->getTimestamp(), 30));
    $administrator->acceptTotpCode(app(Totp::class), $code);

    seeded();

    expect($administrator->refresh()->two_factor_last_step)->toBeNull();
});

it('leaves accounts that are not its own alone', function (): void {
    $other = Administrator::factory()->create(['email' => 'owner@hostmesh.test']);

    seeded();

    expect($other->refresh()->email)->toBe('owner@hostmesh.test')
        ->and(Administrator::query()->count())->toBe(count(Fixtures::ADMINISTRATORS) + 1);
});

// Review focus 4.
it('refuses to run outside the end-to-end environments and writes nothing', function (string $environment): void {
    $this->app->detectEnvironment(fn (): string => $environment);

    expect(Artisan::call('e2e:seed'))->toBe(1)
        ->and(Artisan::output())->toContain('refuses to run')
        ->and(Administrator::query()->count())->toBe(0)
        ->and(User::query()->count())->toBe(0)
        ->and(BrandingRecord::current())->toBeNull();
})->with(['production', 'local', 'staging']);

// A real process: registration happens at boot, from APP_ENV.
it('is not registered outside the end-to-end environments', function (string $environment): void {
    $result = Process::path(base_path())->env(['APP_ENV' => $environment])->run([PHP_BINARY, 'artisan', 'list', '--raw']);

    expect($result->successful())->toBeTrue()
        ->and($result->output())->not->toContain('e2e:seed');
})->with(['production', 'local', 'staging']);

it('is registered in the e2e environment', function (): void {
    $result = Process::path(base_path())->env(['APP_ENV' => 'e2e'])->run([PHP_BINARY, 'artisan', 'list', '--raw']);

    expect($result->output())->toContain('e2e:seed');
});

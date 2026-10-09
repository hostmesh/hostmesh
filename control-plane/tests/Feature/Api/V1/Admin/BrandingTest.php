<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Domain\Authorization\Role;
use App\Domain\Identity\Administrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Browser;
use Tests\Support\StoredBranding;

// Issue #29, spec sections 28 and 37: an administrator reads and edits
// branding under /api/v1/admin/branding. Authorization is a policy on
// `branding.view` and `branding.update` (decision 0012). A color that fails
// WCAG AA is reported in `warnings` and never blocks a save.

uses(RefreshDatabase::class);

const ADMIN_BRANDING = '/api/v1/admin/branding';

beforeEach(function (): void {
    $this->freezeTime();
});

/**
 * An administrator with the role, fully signed in.
 *
 * @return array{Administrator, Browser}
 */
function brandingAdmin(mixed $test, ?Role $role = Role::Owner): array
{
    $factory = Administrator::factory()->withTwoFactor();
    $administrator = ($role === null ? $factory : $factory->withRoles($role))->create();

    $browser = new Browser($test);
    $browser->signIn(Browser::ADMIN, $administrator);

    return [$administrator, $browser];
}

it('shows the defaults, no warnings and what the owner may do', function (): void {
    [, $browser] = brandingAdmin($this);

    $browser->get(Browser::ADMIN, ADMIN_BRANDING)->assertOk()->assertExactJson(['data' => [
        'product_name' => 'Hostmesh',
        'logos' => ['light' => null, 'dark' => null],
        'favicon' => null,
        'colors' => [
            'light' => ['primary' => null, 'primary_foreground' => null],
            'dark' => ['primary' => null, 'primary_foreground' => null],
        ],
        'links' => ['support' => null, 'terms' => null, 'privacy' => null],
        'warnings' => [],
        'abilities' => ['view' => true, 'update' => true],
    ]]);
});

it('shows the same branding as the public endpoint', function (): void {
    StoredBranding::with([
        'product_name' => 'Acme Hosting',
        'logo_dark' => 'cccccccccccccccc.webp',
        'primary_light' => '#1d4ed8',
        'primary_foreground_light' => '#ffffff',
        'terms_url' => 'https://acme.example/terms',
    ]);
    [, $browser] = brandingAdmin($this);

    $admin = $browser->get(Browser::ADMIN, ADMIN_BRANDING)->assertOk()->json('data');
    $public = $this->getJson('https://api.hostmesh.test/api/v1/branding')->assertOk()->json('data');

    unset($admin['warnings'], $admin['abilities']);

    expect($admin)->toBe($public);
});

it('reports the stored colors that fail WCAG AA', function (): void {
    StoredBranding::with(['primary_dark' => '#1d4ed8', 'primary_foreground_dark' => '#ffffff']);
    [, $browser] = brandingAdmin($this);

    $browser->get(Browser::ADMIN, ADMIN_BRANDING)->assertOk()->assertJsonPath('data.warnings', [
        ['mode' => 'dark', 'color' => 'primary', 'against' => 'background', 'ratio' => 2.95, 'required' => 4.5],
        ['mode' => 'dark', 'color' => 'primary', 'against' => 'card', 'ratio' => 2.67, 'required' => 4.5],
    ]);
});

it('lets a read-only administrator view and tells them they may not update', function (): void {
    [, $browser] = brandingAdmin($this, Role::ReadOnly);

    $browser->get(Browser::ADMIN, ADMIN_BRANDING)->assertOk()
        ->assertJsonPath('data.abilities', ['view' => true, 'update' => false]);
});

it('refuses an administrator without a role', function (): void {
    [, $browser] = brandingAdmin($this, null);

    $browser->get(Browser::ADMIN, ADMIN_BRANDING)->assertForbidden();
});

it('refuses a request without a session', function (): void {
    (new Browser($this))->get(Browser::ADMIN, ADMIN_BRANDING)->assertUnauthorized();
});

it('is private: no shared cache may keep the admin answer', function (): void {
    [, $browser] = brandingAdmin($this);

    expect((string) $browser->get(Browser::ADMIN, ADMIN_BRANDING)->headers->get('Cache-Control'))->not->toContain('public');
});

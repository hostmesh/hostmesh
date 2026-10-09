<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Domain\Authorization\Role;
use App\Domain\Branding\BrandingRecord;
use App\Domain\Identity\Administrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
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

/**
 * A body that saves without a warning. Override by dotted key.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function brandingBody(array $overrides = []): array
{
    $body = [
        'product_name' => 'Acme Hosting',
        'colors' => [
            'light' => ['primary' => '#1d4ed8', 'primary_foreground' => '#ffffff'],
            'dark' => ['primary' => '#60a5fa', 'primary_foreground' => '#0a0a0a'],
        ],
        'links' => [
            'support' => 'https://acme.example/help',
            'terms' => 'https://acme.example/terms',
            'privacy' => null,
        ],
    ];

    foreach ($overrides as $key => $value) {
        data_set($body, $key, $value);
    }

    return $body;
}

it('saves branding and returns it without a warning', function (): void {
    [, $browser] = brandingAdmin($this);

    $browser->put(Browser::ADMIN, ADMIN_BRANDING, brandingBody())->assertOk()
        ->assertJsonPath('data.product_name', 'Acme Hosting')
        ->assertJsonPath('data.colors.light', ['primary' => '#1d4ed8', 'primary_foreground' => '#ffffff'])
        ->assertJsonPath('data.colors.dark', ['primary' => '#60a5fa', 'primary_foreground' => '#0a0a0a'])
        ->assertJsonPath('data.links', ['support' => 'https://acme.example/help', 'terms' => 'https://acme.example/terms', 'privacy' => null])
        ->assertJsonPath('data.warnings', [])
        ->assertJsonPath('data.abilities.update', true);

    $this->assertDatabaseCount('branding', 1);
    $this->assertDatabaseHas('branding', ['id' => BrandingRecord::ID, 'product_name' => 'Acme Hosting', 'primary_light' => '#1d4ed8']);
});

it('shows a saved change on the public endpoint, under a new ETag', function (): void {
    $before = (string) $this->getJson('https://api.hostmesh.test/api/v1/branding')->headers->get('ETag');
    [, $browser] = brandingAdmin($this);

    $browser->put(Browser::ADMIN, ADMIN_BRANDING, brandingBody())->assertOk();

    $response = $this->getJson('https://api.hostmesh.test/api/v1/branding')->assertOk()
        ->assertJsonPath('data.product_name', 'Acme Hosting')
        ->assertJsonPath('data.colors.dark.primary', '#60a5fa')
        ->assertJsonPath('data.links.support', 'https://acme.example/help');

    expect($response->headers->get('ETag'))->not->toBe($before);
});

it('replaces every field: a null clears what was stored', function (): void {
    [, $browser] = brandingAdmin($this);
    $browser->put(Browser::ADMIN, ADMIN_BRANDING, brandingBody())->assertOk();

    $browser->put(Browser::ADMIN, ADMIN_BRANDING, brandingBody([
        'colors.light.primary' => null,
        'colors.light.primary_foreground' => null,
        'links.support' => null,
    ]))->assertOk()
        ->assertJsonPath('data.colors.light', ['primary' => null, 'primary_foreground' => null])
        ->assertJsonPath('data.links.support', null);
});

it('saves a color that fails WCAG AA and returns the warning', function (): void {
    [, $browser] = brandingAdmin($this);

    $response = $browser->put(Browser::ADMIN, ADMIN_BRANDING, brandingBody([
        'colors.light.primary' => '#ffff00',
        'colors.light.primary_foreground' => null,
    ]))->assertOk()->assertJsonPath('data.colors.light.primary', '#ffff00');

    expect(array_map(
        fn (array $warning): array => [$warning['mode'], $warning['color'], $warning['against'], $warning['required']],
        $response->json('data.warnings'),
    ))->toBe([
        ['light', 'primary_foreground', 'primary', 4.5],
        ['light', 'primary', 'background', 4.5],
        ['light', 'primary', 'card', 4.5],
    ]);

    $this->assertDatabaseHas('branding', ['primary_light' => '#ffff00']);
});

it('accepts a color in upper case and stores it in lower case', function (): void {
    [, $browser] = brandingAdmin($this);

    $browser->put(Browser::ADMIN, ADMIN_BRANDING, brandingBody(['colors.light.primary' => '#1D4ED8']))->assertOk()
        ->assertJsonPath('data.colors.light.primary', '#1d4ed8');
});

it('refuses a color that is not #rrggbb', function (string $field, mixed $value): void {
    [, $browser] = brandingAdmin($this);

    $browser->put(Browser::ADMIN, ADMIN_BRANDING, brandingBody([$field => $value]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    $this->assertDatabaseCount('branding', 0);
})->with([
    'colors.light.primary',
    'colors.light.primary_foreground',
    'colors.dark.primary',
    'colors.dark.primary_foreground',
])->with([
    'shorthand' => ['#fff'],
    'no hash' => ['ffffff'],
    'alpha channel' => ['#ffffff80'],
    'not hexadecimal' => ['#gggggg'],
    'a name' => ['red'],
    'a CSS function' => ['rgb(0, 0, 0)'],
    'a number' => [123456],
    'a list' => [['#ffffff']],
]);

it('refuses a link that is not an https URL', function (string $field, mixed $value): void {
    [, $browser] = brandingAdmin($this);

    $browser->put(Browser::ADMIN, ADMIN_BRANDING, brandingBody([$field => $value]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    $this->assertDatabaseCount('branding', 0);
})->with(['links.support', 'links.terms', 'links.privacy'])->with([
    'plain http' => ['http://acme.example/help'],
    'a script' => ['javascript:alert(1)'],
    'a mail address' => ['mailto:help@acme.example'],
    'no scheme' => ['acme.example/help'],
    'scheme-relative' => ['//acme.example/help'],
    'a data URL' => ['data:text/html,<p>hi</p>'],
    'too long' => ['https://acme.example/'.str_repeat('a', 2048)],
    'a number' => [42],
]);

it('refuses a product name that is missing, too long or holds a control character', function (mixed $value): void {
    [, $browser] = brandingAdmin($this);

    $browser->put(Browser::ADMIN, ADMIN_BRANDING, brandingBody(['product_name' => $value]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['product_name']);
})->with([
    'null' => [null],
    'empty' => [''],
    'only spaces' => ['   '],
    '61 characters' => [str_repeat('a', 61)],
    'a bell character' => ["Acme\u{0007}Hosting"],
    'a line break' => ["Acme\nHosting"],
    'a list' => [['Acme']],
]);

it('accepts a product name of 60 characters in any script', function (): void {
    [, $browser] = brandingAdmin($this);
    $name = str_repeat('Ω', 60);

    $browser->put(Browser::ADMIN, ADMIN_BRANDING, brandingBody(['product_name' => $name]))->assertOk()
        ->assertJsonPath('data.product_name', $name);
});

it('refuses a body that leaves a field out: PUT replaces the whole record', function (string $field): void {
    [, $browser] = brandingAdmin($this);
    $body = brandingBody();
    Arr::forget($body, $field);

    $browser->put(Browser::ADMIN, ADMIN_BRANDING, $body)->assertUnprocessable();

    $this->assertDatabaseCount('branding', 0);
})->with(['product_name', 'colors', 'colors.dark', 'colors.light.primary', 'links', 'links.privacy']);

it('refuses to save for an administrator who may only view', function (?Role $role): void {
    [, $browser] = brandingAdmin($this, $role);

    $browser->put(Browser::ADMIN, ADMIN_BRANDING, brandingBody())->assertForbidden();

    $this->assertDatabaseCount('branding', 0);
})->with(['read-only' => [Role::ReadOnly], 'no role' => [null]]);

it('refuses before it validates: an account without the permission learns nothing about the fields', function (): void {
    [, $browser] = brandingAdmin($this, Role::ReadOnly);

    $browser->put(Browser::ADMIN, ADMIN_BRANDING, ['product_name' => ''])->assertForbidden();
});

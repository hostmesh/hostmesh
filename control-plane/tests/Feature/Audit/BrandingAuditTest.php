<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Application\Commands\BrandingChanges;
use App\Application\Commands\UpdateBranding;
use App\Domain\Audit\AuditLog;
use App\Domain\Authorization\Role;
use App\Domain\Branding\BrandingRecord;
use App\Domain\Identity\Administrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AuditTrail;
use Tests\Support\Browser;
use Tests\Support\InMemoryAuditLog;

// Issue #29, decision 0013: every branding change is audited in the
// transaction that makes it. An event names the fields that changed, never
// their values. A change whose event cannot be written does not happen.

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeTime();
});

/**
 * An owner, fully signed in. Signing in writes two events of its own.
 *
 * @return array{Administrator, Browser}
 */
function auditedBrandingOwner(mixed $test): array
{
    $owner = Administrator::factory()->withTwoFactor()->withRoles(Role::Owner)->create();
    $browser = new Browser($test);
    $browser->signIn(Browser::ADMIN, $owner);

    return [$owner, $browser];
}

/** @return list<string> the actions recorded after the two events of signing in */
function brandingActions(): array
{
    return array_slice(AuditTrail::actions(), 2);
}

/** @return array<string, mixed> */
function auditedBrandingBody(string $productName = 'Acme Hosting', ?string $primaryLight = '#1d4ed8'): array
{
    return [
        'product_name' => $productName,
        'colors' => [
            'light' => ['primary' => $primaryLight, 'primary_foreground' => '#ffffff'],
            'dark' => ['primary' => null, 'primary_foreground' => null],
        ],
        'links' => ['support' => 'https://acme.example/help', 'terms' => null, 'privacy' => null],
    ];
}

it('records who changed which fields, and none of the values', function (): void {
    [$owner, $browser] = auditedBrandingOwner($this);

    $browser->put(Browser::ADMIN, '/api/v1/admin/branding', auditedBrandingBody())->assertOk();

    $event = AuditTrail::last();

    expect(brandingActions())->toBe(['branding.updated'])
        ->and($event->actor_type)->toBe('administrator')
        ->and($event->actor_id)->toBe($owner->id)
        ->and($event->resource)->toBe('branding')
        ->and($event->resource_id)->toBe(BrandingRecord::ID)
        ->and(AuditTrail::metadata($event))->toBe(['fields' => 'primary_foreground_light,primary_light,product_name,support_url'])
        ->and($event->ip_address)->toBe('127.0.0.1')
        ->and($event->request_id)->not->toBeNull();

    AuditTrail::assertHoldsNone('Acme Hosting', 'acme.example', '#1d4ed8', $owner->email, $owner->name);
});

it('names only the fields that changed', function (): void {
    [, $browser] = auditedBrandingOwner($this);
    $browser->put(Browser::ADMIN, '/api/v1/admin/branding', auditedBrandingBody())->assertOk();

    $browser->put(Browser::ADMIN, '/api/v1/admin/branding', auditedBrandingBody(primaryLight: null))->assertOk();

    expect(brandingActions())->toBe(['branding.updated', 'branding.updated'])
        ->and(AuditTrail::metadata(AuditTrail::last()))->toBe(['fields' => 'primary_light']);
});

it('records nothing for a save that changes nothing', function (): void {
    [, $browser] = auditedBrandingOwner($this);
    $browser->put(Browser::ADMIN, '/api/v1/admin/branding', auditedBrandingBody())->assertOk();

    $browser->put(Browser::ADMIN, '/api/v1/admin/branding', auditedBrandingBody())->assertOk();

    expect(brandingActions())->toBe(['branding.updated']);
});

it('records nothing for a first save that equals the defaults', function (): void {
    [, $browser] = auditedBrandingOwner($this);

    $browser->put(Browser::ADMIN, '/api/v1/admin/branding', [
        'product_name' => 'Hostmesh',
        'colors' => [
            'light' => ['primary' => null, 'primary_foreground' => null],
            'dark' => ['primary' => null, 'primary_foreground' => null],
        ],
        'links' => ['support' => null, 'terms' => null, 'privacy' => null],
    ])->assertOk();

    expect(brandingActions())->toBe([]);
});

it('records nothing for a refused or invalid save', function (): void {
    [, $browser] = auditedBrandingOwner($this);

    $browser->put(Browser::ADMIN, '/api/v1/admin/branding', auditedBrandingBody(primaryLight: 'red'))->assertUnprocessable();

    expect(brandingActions())->toBe([]);
});

it('does not save when the audit log cannot be written', function (): void {
    [, $browser] = auditedBrandingOwner($this);
    $this->app->instance(AuditLog::class, InMemoryAuditLog::failing());

    $browser->put(Browser::ADMIN, '/api/v1/admin/branding', auditedBrandingBody())->assertStatus(500);

    expect(BrandingRecord::current())->toBeNull();
});

it('keeps the stored branding when a later change cannot be audited', function (): void {
    [, $browser] = auditedBrandingOwner($this);
    $browser->put(Browser::ADMIN, '/api/v1/admin/branding', auditedBrandingBody())->assertOk();
    $this->app->instance(AuditLog::class, InMemoryAuditLog::failing());

    $browser->put(Browser::ADMIN, '/api/v1/admin/branding', auditedBrandingBody('Other Name'))->assertStatus(500);

    expect(BrandingRecord::current()?->product_name)->toBe('Acme Hosting');
});

it('records a change made by the system without an actor', function (): void {
    app(UpdateBranding::class)->handle(null, new BrandingChanges(
        productName: 'Acme Hosting',
        primaryLight: null,
        primaryForegroundLight: null,
        primaryDark: null,
        primaryForegroundDark: null,
        supportUrl: null,
        termsUrl: null,
        privacyUrl: null,
    ));

    $event = AuditTrail::last();

    expect(AuditTrail::actions())->toBe(['branding.updated'])
        ->and($event->actor_type)->toBe('system')
        ->and($event->actor_id)->toBeNull()
        ->and(AuditTrail::metadata($event))->toBe(['fields' => 'product_name']);
});

it('refuses a color the application layer does not recognise, whoever calls it', function (): void {
    $changes = new BrandingChanges(
        productName: 'Acme Hosting',
        primaryLight: 'red',
        primaryForegroundLight: null,
        primaryDark: null,
        primaryForegroundDark: null,
        supportUrl: null,
        termsUrl: null,
        privacyUrl: null,
    );

    expect(fn () => app(UpdateBranding::class)->handle(null, $changes))->toThrow(InvalidArgumentException::class)
        ->and(BrandingRecord::current())->toBeNull();
});

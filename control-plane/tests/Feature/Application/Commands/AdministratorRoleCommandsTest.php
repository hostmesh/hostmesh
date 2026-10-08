<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Application\Commands\AssignAdministratorRole;
use App\Application\Commands\RevokeAdministratorRole;
use App\Domain\Audit\AuditLog;
use App\Domain\Authorization\Permission;
use App\Domain\Authorization\Role;
use App\Domain\Identity\Administrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AuditTrail;
use Tests\Support\InMemoryAuditLog;

// Issue #27, decisions 0012 and 0013: role assignment is data, and every
// change to it is audited in the same transaction.

uses(RefreshDatabase::class);

it('assigns a role and records who gave it to whom', function (): void {
    $owner = Administrator::factory()->withRoles(Role::Owner)->create();
    $administrator = Administrator::factory()->create();

    $assigned = app(AssignAdministratorRole::class)->handle($owner, $administrator, Role::ReadOnly);

    $event = AuditTrail::last();

    expect($assigned)->toBeTrue()
        ->and($administrator->roles())->toBe([Role::ReadOnly])
        ->and($administrator->hasPermission(Permission::AdministratorView))->toBeTrue()
        ->and(AuditTrail::actions())->toBe(['administrator.role_assigned'])
        ->and($event->actor_type)->toBe('administrator')
        ->and($event->actor_id)->toBe($owner->id)
        ->and($event->resource)->toBe('administrator')
        ->and($event->resource_id)->toBe($administrator->id)
        ->and(AuditTrail::metadata($event))->toBe(['role' => 'read-only']);

    $this->assertDatabaseHas('administrator_roles', ['administrator_id' => $administrator->id, 'role' => 'read-only']);
});

it('changes and records nothing when the administrator already holds the role', function (): void {
    $owner = Administrator::factory()->withRoles(Role::Owner)->create();
    $administrator = Administrator::factory()->withRoles(Role::ReadOnly)->create();

    $assigned = app(AssignAdministratorRole::class)->handle($owner, $administrator, Role::ReadOnly);

    expect($assigned)->toBeFalse()
        ->and($administrator->roles())->toBe([Role::ReadOnly])
        ->and(AuditTrail::all())->toBeEmpty();
});

it('revokes a role and records who took it from whom', function (): void {
    $owner = Administrator::factory()->withRoles(Role::Owner)->create();
    $administrator = Administrator::factory()->withRoles(Role::ReadOnly, Role::Owner)->create();

    $revoked = app(RevokeAdministratorRole::class)->handle($owner, $administrator, Role::Owner);

    $event = AuditTrail::last();

    expect($revoked)->toBeTrue()
        ->and($administrator->roles())->toBe([Role::ReadOnly])
        ->and($administrator->hasPermission(Permission::AdministratorUpdate))->toBeFalse()
        ->and(AuditTrail::actions())->toBe(['administrator.role_revoked'])
        ->and($event->actor_id)->toBe($owner->id)
        ->and($event->resource_id)->toBe($administrator->id)
        ->and(AuditTrail::metadata($event))->toBe(['role' => 'owner']);

    $this->assertDatabaseMissing('administrator_roles', ['administrator_id' => $administrator->id, 'role' => 'owner']);
});

it('changes and records nothing when the administrator does not hold the role', function (): void {
    $owner = Administrator::factory()->withRoles(Role::Owner)->create();
    $administrator = Administrator::factory()->create();

    $revoked = app(RevokeAdministratorRole::class)->handle($owner, $administrator, Role::ReadOnly);

    expect($revoked)->toBeFalse()
        ->and(AuditTrail::all())->toBeEmpty();
});

it('records the system as the actor when no administrator made the change', function (): void {
    $administrator = Administrator::factory()->create();

    app(AssignAdministratorRole::class)->handle(null, $administrator, Role::Owner);

    $event = AuditTrail::last();

    expect($event->actor_type)->toBe('system')
        ->and($event->actor_id)->toBeNull()
        ->and($event->resource_id)->toBe($administrator->id);
});

it('stores no name or address of either administrator', function (): void {
    $owner = Administrator::factory()->withRoles(Role::Owner)->create();
    $administrator = Administrator::factory()->create();

    app(AssignAdministratorRole::class)->handle($owner, $administrator, Role::ReadOnly);
    app(RevokeAdministratorRole::class)->handle($owner, $administrator, Role::ReadOnly);

    expect(AuditTrail::all())->toHaveCount(2);

    AuditTrail::assertHoldsNone($owner->email, $owner->name, $administrator->email, $administrator->name);
});

it('does not assign the role when the audit event cannot be written', function (): void {
    $owner = Administrator::factory()->withRoles(Role::Owner)->create();
    $administrator = Administrator::factory()->create();
    $this->app->instance(AuditLog::class, InMemoryAuditLog::failing());

    expect(fn () => app(AssignAdministratorRole::class)->handle($owner, $administrator, Role::Owner))
        ->toThrow(RuntimeException::class, 'The audit log cannot be written.');

    $this->assertDatabaseMissing('administrator_roles', ['administrator_id' => $administrator->id]);
    expect($administrator->fresh()->roles())->toBe([]);
});

it('does not revoke the role when the audit event cannot be written', function (): void {
    $owner = Administrator::factory()->withRoles(Role::Owner)->create();
    $administrator = Administrator::factory()->withRoles(Role::ReadOnly)->create();
    $this->app->instance(AuditLog::class, InMemoryAuditLog::failing());

    expect(fn () => app(RevokeAdministratorRole::class)->handle($owner, $administrator, Role::ReadOnly))
        ->toThrow(RuntimeException::class, 'The audit log cannot be written.')
        ->and($administrator->fresh()->roles())->toBe([Role::ReadOnly]);
});

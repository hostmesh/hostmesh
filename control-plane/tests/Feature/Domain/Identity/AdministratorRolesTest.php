<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Domain\Authorization\Permission;
use App\Domain\Authorization\Role;
use App\Domain\Identity\Administrator;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

// Decision 0012: role assignment is data, in `administrator_roles`.
// Administrator::hasPermission() is the single seam the policies use.

uses(RefreshDatabase::class);

it('gives an administrator without a role no permission', function (): void {
    $administrator = Administrator::factory()->create();

    foreach (Permission::cases() as $permission) {
        expect($administrator->hasPermission($permission))->toBeFalse($permission->value);
    }
});

it('gives an administrator the permissions of the assigned role', function (): void {
    $owner = Administrator::factory()->withRoles(Role::Owner)->create();
    $readOnly = Administrator::factory()->withRoles(Role::ReadOnly)->create();

    expect($owner->hasPermission(Permission::AdministratorUpdate))->toBeTrue()
        ->and($readOnly->hasPermission(Permission::AdministratorView))->toBeTrue()
        ->and($readOnly->hasPermission(Permission::AdministratorUpdate))->toBeFalse();

    $this->assertDatabaseHas('administrator_roles', ['administrator_id' => $owner->id, 'role' => 'owner']);
});

it('adds up the permissions of several roles', function (): void {
    $administrator = Administrator::factory()->withRoles(Role::ReadOnly, Role::Owner)->create();

    expect($administrator->roles())->toEqualCanonicalizing([Role::ReadOnly, Role::Owner])
        ->and($administrator->hasPermission(Permission::AdministratorUpdate))->toBeTrue();
});

it('does not assign a role to the other administrators', function (): void {
    Administrator::factory()->withRoles(Role::Owner)->create();
    $other = Administrator::factory()->create();

    expect($other->roles())->toBe([])
        ->and($other->hasPermission(Permission::AdministratorView))->toBeFalse();
});

it('reads the roles once, however many permissions are checked', function (): void {
    $administrator = Administrator::factory()->withRoles(Role::ReadOnly)->create()->fresh();

    DB::enableQueryLog();

    foreach ([...Permission::cases(), ...Permission::cases()] as $permission) {
        $administrator->hasPermission($permission);
    }

    expect(DB::getQueryLog())->toHaveCount(1);
});

it('refuses the same role twice for one administrator', function (): void {
    $administrator = Administrator::factory()->withRoles(Role::Owner)->create();

    expect(fn () => DB::table('administrator_roles')->insert([
        'administrator_id' => $administrator->id,
        'role' => 'owner',
    ]))->toThrow(QueryException::class);
});

it('refuses a role for an administrator who does not exist', function (): void {
    expect(fn () => DB::table('administrator_roles')->insert([
        'administrator_id' => '00000000-0000-0000-0000-000000000000',
        'role' => 'owner',
    ]))->toThrow(QueryException::class);
});

it('removes the assignments with the administrator', function (): void {
    $administrator = Administrator::factory()->withRoles(Role::Owner)->create();

    $administrator->delete();

    $this->assertDatabaseMissing('administrator_roles', ['administrator_id' => $administrator->id]);
});

it('ignores an assigned role that the code no longer defines', function (): void {
    $administrator = Administrator::factory()->withRoles(Role::ReadOnly)->create();
    DB::table('administrator_roles')->insert(['administrator_id' => $administrator->id, 'role' => 'retired-role']);

    expect($administrator->roles())->toBe([Role::ReadOnly])
        ->and($administrator->hasPermission(Permission::AdministratorUpdate))->toBeFalse();
});

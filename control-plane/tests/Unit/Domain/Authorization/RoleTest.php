<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Domain\Authorization\Permission;
use App\Domain\Authorization\Role;

// Decision 0012: permissions are a backed enum and a role is a named set of
// them, both defined in code.

it('starts with the two roles of decision 0012', function (): void {
    expect(array_map(fn (Role $role): string => $role->value, Role::cases()))
        ->toBe(['owner', 'read-only']);
});

it('names a permission as resource.action', function (): void {
    foreach (Permission::cases() as $permission) {
        expect($permission->value)->toMatch('/^[a-z]+(-[a-z]+)*\.[a-z]+(-[a-z]+)*$/');
    }
});

it('lets the owner do everything', function (): void {
    expect(Role::Owner->permissions())->toEqualCanonicalizing(Permission::cases());

    foreach (Permission::cases() as $permission) {
        expect(Role::Owner->grants($permission))->toBeTrue($permission->value);
    }
});

it('lets read-only view everything and change nothing', function (): void {
    foreach (Permission::cases() as $permission) {
        expect(Role::ReadOnly->grants($permission))
            ->toBe(str_ends_with($permission->value, '.view'), $permission->value);
    }
});

it('gives read-only something to view', function (): void {
    expect(Role::ReadOnly->permissions())->not->toBeEmpty()
        ->toContain(Permission::AdministratorView, Permission::UserView)
        ->not->toContain(Permission::AdministratorUpdate, Permission::UserUpdate);
});

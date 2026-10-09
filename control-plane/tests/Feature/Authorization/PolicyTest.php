<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Domain\Audit\AuditEventRecord;
use App\Domain\Authorization\Role;
use App\Domain\Branding\BrandingRecord;
use App\Domain\Identity\Administrator;
use App\Domain\Identity\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

// Issue #26, decision 0012, spec section 28: who may do what. Policies are
// the only place where authorization is decided. An administrator is checked
// against a permission of their roles, a customer's user against ownership.
// A new resource adds its own table to this file.

uses(RefreshDatabase::class);

const ABILITIES = ['view', 'update', 'manageTwoFactor'];

/** @return array<string, bool> the Gate's answer for each ability */
function decisions(Administrator|User|null $actor, Administrator|User $target): array
{
    $decisions = [];

    foreach (ABILITIES as $ability) {
        $decisions[$ability] = Gate::forUser($actor)->allows($ability, $target);
    }

    return $decisions;
}

/** @return array<string, bool> */
function allow(string ...$abilities): array
{
    $decisions = [];

    foreach (ABILITIES as $ability) {
        $decisions[$ability] = in_array($ability, $abilities, true);
    }

    return $decisions;
}

function administratorWith(?Role $role): Administrator
{
    return $role === null
        ? Administrator::factory()->create()
        : Administrator::factory()->withRoles($role)->create();
}

dataset('administrator on themselves', [
    'owner' => [Role::Owner, ['view', 'update', 'manageTwoFactor']],
    'read-only' => [Role::ReadOnly, ['view', 'manageTwoFactor']],
    'no role' => [null, ['view', 'manageTwoFactor']],
]);

dataset('administrator on another administrator', [
    'owner' => [Role::Owner, ['view', 'update']],
    'read-only' => [Role::ReadOnly, ['view']],
    'no role' => [null, []],
]);

dataset('administrator on a user', [
    'owner' => [Role::Owner, ['view', 'update']],
    'read-only' => [Role::ReadOnly, ['view']],
    'no role' => [null, []],
]);

it('decides what an administrator may do with their own account', function (?Role $role, array $allowed): void {
    $administrator = administratorWith($role);

    expect(decisions($administrator, $administrator))->toBe(allow(...$allowed));
})->with('administrator on themselves');

it('decides what an administrator may do with another administrator', function (?Role $role, array $allowed): void {
    expect(decisions(administratorWith($role), Administrator::factory()->create()))->toBe(allow(...$allowed));
})->with('administrator on another administrator');

it('decides what an administrator may do with a user', function (?Role $role, array $allowed): void {
    expect(decisions(administratorWith($role), User::factory()->create()))->toBe(allow(...$allowed));
})->with('administrator on a user');

it('never lets one account manage the second factor of another', function (): void {
    $owner = administratorWith(Role::Owner);

    expect(Gate::forUser($owner)->allows('manageTwoFactor', Administrator::factory()->create()))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('manageTwoFactor', User::factory()->create()))->toBeFalse();
});

it('lets a user do everything with their own account', function (): void {
    $user = User::factory()->create();

    expect(decisions($user, $user))->toBe(allow('view', 'update', 'manageTwoFactor'));
});

it('lets a user do nothing with a stranger', function (): void {
    expect(decisions(User::factory()->create(), User::factory()->create()))->toBe(allow());
});

it('lets a user do nothing with an administrator', function (): void {
    $user = User::factory()->create();

    expect(decisions($user, Administrator::factory()->create()))->toBe(allow())
        // Not even with the administrator who happens to share the user's key.
        ->and(decisions($user, Administrator::factory()->create(['id' => $user->id])))->toBe(allow());
});

it('does not take an administrator for the user with the same key', function (): void {
    $administrator = Administrator::factory()->create();
    $user = User::factory()->create(['id' => $administrator->id]);

    expect(decisions($administrator, $user))->toBe(allow());
});

it('lets a guest do nothing', function (): void {
    expect(decisions(null, Administrator::factory()->create()))->toBe(allow())
        ->and(decisions(null, User::factory()->create()))->toBe(allow());
});

it('denies an ability no policy defines', function (): void {
    $owner = administratorWith(Role::Owner);

    expect(Gate::forUser($owner)->allows('destroyEverything', $owner))->toBeFalse();
});

it('decides which administrator may read the audit log', function (?Role $role, bool $allowed): void {
    $administrator = administratorWith($role);

    expect(Gate::forUser($administrator)->allows('viewAny', AuditEventRecord::class))->toBe($allowed)
        ->and(Gate::forUser($administrator)->allows('view', new AuditEventRecord))->toBe($allowed);
})->with([
    'owner' => [Role::Owner, true],
    'read-only' => [Role::ReadOnly, true],
    'no role' => [null, false],
]);

it('lets no customer and no guest read the audit log', function (): void {
    $user = User::factory()->create();

    expect(Gate::forUser($user)->allows('viewAny', AuditEventRecord::class))->toBeFalse()
        ->and(Gate::forUser($user)->allows('view', new AuditEventRecord))->toBeFalse()
        ->and(Gate::forUser(null)->allows('viewAny', AuditEventRecord::class))->toBeFalse()
        ->and(Gate::forUser(null)->allows('view', new AuditEventRecord))->toBeFalse();
});

it('lets nobody change or delete an audit event', function (): void {
    $owner = administratorWith(Role::Owner);

    foreach (['create', 'update', 'delete', 'restore', 'forceDelete'] as $ability) {
        expect(Gate::forUser($owner)->allows($ability, new AuditEventRecord))->toBeFalse($ability);
    }
});

it('decides which administrator may view and update branding', function (?Role $role, bool $view, bool $update): void {
    $administrator = administratorWith($role);

    expect(Gate::forUser($administrator)->allows('view', new BrandingRecord))->toBe($view)
        ->and(Gate::forUser($administrator)->allows('update', new BrandingRecord))->toBe($update);
})->with([
    'owner' => [Role::Owner, true, true],
    'read-only' => [Role::ReadOnly, true, false],
    'no role' => [null, false, false],
]);

it('lets no customer and no guest view or update branding through the admin policy', function (): void {
    $user = User::factory()->create();

    foreach (['view', 'update'] as $ability) {
        expect(Gate::forUser($user)->allows($ability, new BrandingRecord))->toBeFalse($ability)
            ->and(Gate::forUser(null)->allows($ability, new BrandingRecord))->toBeFalse($ability);
    }
});

it('lets nobody create or delete the branding record', function (): void {
    $owner = administratorWith(Role::Owner);

    foreach (['create', 'delete', 'restore', 'forceDelete'] as $ability) {
        expect(Gate::forUser($owner)->allows($ability, new BrandingRecord))->toBeFalse($ability);
    }
});

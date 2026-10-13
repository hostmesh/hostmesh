<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Application\Commands\CreateAdministrator;
use App\Application\Exceptions\AdministratorExists;
use App\Domain\Audit\AuditLog;
use App\Domain\Authorization\Role;
use App\Domain\Identity\Administrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\AuditTrail;
use Tests\Support\InMemoryAuditLog;

// Issue #122: the only way an installation gets an administrator until
// there are screens for it. Audited like every other change (decision 0013).

uses(RefreshDatabase::class);

it('creates an administrator with a role and a generated password', function (): void {
    $created = app(CreateAdministrator::class)->handle(null, 'Ada Lovelace', 'ada@example.com', Role::Owner);

    $administrator = Administrator::query()->where('email', 'ada@example.com')->sole();

    expect($created->administrator->is($administrator))->toBeTrue()
        ->and($administrator->name)->toBe('Ada Lovelace')
        ->and($administrator->roles())->toBe([Role::Owner])
        ->and(strlen($created->password))->toBe(40)
        ->and(Hash::check($created->password, $administrator->password))->toBeTrue()
        // The first login leads into enrollment.
        ->and($administrator->hasTwoFactor())->toBeFalse();
});

it('gives every administrator a password of its own', function (): void {
    $create = app(CreateAdministrator::class);

    $first = $create->handle(null, 'Ada', 'ada@example.com', Role::Owner);
    $second = $create->handle(null, 'Grace', 'grace@example.com', Role::ReadOnly);

    expect($second->password)->not->toBe($first->password)
        ->and($second->administrator->roles())->toBe([Role::ReadOnly]);
});

it('records the creation and the role, with the system as actor and no personal data', function (): void {
    $created = app(CreateAdministrator::class)->handle(null, 'Ada Lovelace', 'ada@example.com', Role::Owner);

    [$creation, $role] = AuditTrail::all()->all();

    expect(AuditTrail::actions())->toBe(['administrator.created', 'administrator.role_assigned'])
        ->and($creation->actor_type)->toBe('system')
        ->and($creation->actor_id)->toBeNull()
        ->and($creation->resource)->toBe('administrator')
        ->and($creation->resource_id)->toBe($created->administrator->id)
        ->and(AuditTrail::metadata($creation))->toBe([])
        ->and($role->actor_type)->toBe('system')
        ->and(AuditTrail::metadata($role))->toBe(['role' => 'owner']);

    AuditTrail::assertHoldsNone($created->password, 'ada@example.com', 'Ada Lovelace');
});

it('records the administrator who created another', function (): void {
    $owner = Administrator::factory()->withRoles(Role::Owner)->create();

    app(CreateAdministrator::class)->handle($owner, 'Grace', 'grace@example.com', Role::ReadOnly);

    $creation = AuditTrail::all()->first();

    expect($creation->actor_type)->toBe('administrator')
        ->and($creation->actor_id)->toBe($owner->id);
});

it('refuses an address that is taken, in any case, and changes nothing', function (string $email): void {
    Administrator::factory()->create(['email' => 'ada@example.com']);

    expect(fn () => app(CreateAdministrator::class)->handle(null, 'Another Ada', $email, Role::Owner))
        ->toThrow(AdministratorExists::class, 'ada@example.com');

    expect(Administrator::query()->count())->toBe(1)
        ->and(AuditTrail::all())->toBeEmpty();
})->with(['ada@example.com', 'Ada@Example.COM']);

// Two operators, one address, the same moment: both pass the check, and
// the unique index decides. Made deterministic here: the other creation
// lands just before this one is saved.
it('refuses an address that another creation took in the meantime', function (): void {
    Administrator::creating(function (Administrator $administrator): void {
        DB::table('administrators')->insert([
            'id' => (string) Str::uuid7(),
            'name' => 'The other one',
            'email' => $administrator->email,
            'password' => 'irrelevant',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    try {
        expect(fn () => app(CreateAdministrator::class)->handle(null, 'Ada', 'ada@example.com', Role::Owner))
            ->toThrow(AdministratorExists::class, 'ada@example.com');
    } finally {
        Administrator::flushEventListeners();
    }

    // The other creation was part of the transaction that failed.
    expect(Administrator::query()->count())->toBe(0)
        ->and(AuditTrail::all())->toBeEmpty();
});

it('creates nothing when the audit log cannot be written', function (): void {
    $this->app->instance(AuditLog::class, InMemoryAuditLog::failing());

    expect(fn () => app(CreateAdministrator::class)->handle(null, 'Ada', 'ada@example.com', Role::Owner))
        ->toThrow(RuntimeException::class);

    expect(Administrator::query()->count())->toBe(0);
    $this->assertDatabaseCount('administrator_roles', 0);
});

<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Application\Commands\CreateCustomer;
use App\Application\Exceptions\CustomerExists;
use App\Domain\Audit\AuditLog;
use App\Domain\Identity\Administrator;
use App\Domain\Identity\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\AuditTrail;
use Tests\Support\InMemoryAuditLog;

// Issue #134, decision 0022: until registration comes with checkout
// (Slice 6), an operator creates a customer's account. Audited like every
// other change (decision 0013).

uses(RefreshDatabase::class);

it('creates a customer with a generated password', function (): void {
    $created = app(CreateCustomer::class)->handle(null, 'Grace Hopper', 'grace@example.com');

    $user = User::query()->where('email', 'grace@example.com')->sole();

    expect($created->user->is($user))->toBeTrue()
        ->and($user->name)->toBe('Grace Hopper')
        ->and(strlen($created->password))->toBe(40)
        ->and(Hash::check($created->password, $user->password))->toBeTrue()
        ->and($user->hasTwoFactor())->toBeFalse();
});

it('gives every customer a password of its own', function (): void {
    $create = app(CreateCustomer::class);

    $first = $create->handle(null, 'Grace', 'grace@example.com');
    $second = $create->handle(null, 'Ada', 'ada@example.com');

    expect($second->password)->not->toBe($first->password);
});

it('records the creation, with the system as actor and no personal data', function (): void {
    $created = app(CreateCustomer::class)->handle(null, 'Grace Hopper', 'grace@example.com');

    $creation = AuditTrail::all()->sole();

    expect($creation->action)->toBe('user.created')
        ->and($creation->actor_type)->toBe('system')
        ->and($creation->actor_id)->toBeNull()
        ->and($creation->resource)->toBe('user')
        ->and($creation->resource_id)->toBe($created->user->id)
        ->and(AuditTrail::metadata($creation))->toBe([]);

    AuditTrail::assertHoldsNone($created->password, 'grace@example.com', 'Grace Hopper');
});

it('records the administrator who created the customer', function (): void {
    $owner = Administrator::factory()->create();

    app(CreateCustomer::class)->handle($owner, 'Grace', 'grace@example.com');

    $creation = AuditTrail::all()->sole();

    expect($creation->actor_type)->toBe('administrator')
        ->and($creation->actor_id)->toBe($owner->id);
});

it('refuses an address that is taken, in any case, and changes nothing', function (string $email): void {
    User::factory()->create(['email' => 'grace@example.com']);

    expect(fn () => app(CreateCustomer::class)->handle(null, 'Another Grace', $email))
        ->toThrow(CustomerExists::class, 'grace@example.com');

    expect(User::query()->count())->toBe(1)
        ->and(AuditTrail::all())->toBeEmpty();
})->with(['grace@example.com', 'Grace@Example.COM']);

// An administrator and a customer are different realms (decision 0010):
// the same address may have both.
it('creates a customer with the address of an administrator', function (): void {
    Administrator::factory()->create(['email' => 'grace@example.com']);

    app(CreateCustomer::class)->handle(null, 'Grace', 'grace@example.com');

    expect(User::query()->where('email', 'grace@example.com')->exists())->toBeTrue();
});

it('refuses an address that another creation took in the meantime', function (): void {
    User::creating(function (User $user): void {
        DB::table('users')->insert([
            'id' => (string) Str::uuid7(),
            'name' => 'The other one',
            'email' => $user->email,
            'password' => 'irrelevant',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    try {
        expect(fn () => app(CreateCustomer::class)->handle(null, 'Grace', 'grace@example.com'))
            ->toThrow(CustomerExists::class, 'grace@example.com');
    } finally {
        User::flushEventListeners();
    }

    expect(User::query()->count())->toBe(0)
        ->and(AuditTrail::all())->toBeEmpty();
});

it('creates nothing when the audit log cannot be written', function (): void {
    $this->app->instance(AuditLog::class, InMemoryAuditLog::failing());

    expect(fn () => app(CreateCustomer::class)->handle(null, 'Grace', 'grace@example.com'))
        ->toThrow(RuntimeException::class);

    expect(User::query()->count())->toBe(0);
});

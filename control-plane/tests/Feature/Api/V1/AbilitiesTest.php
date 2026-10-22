<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Domain\Audit\AuditEventRecord;
use App\Domain\Authorization\Role;
use App\Domain\Branding\BrandingRecord;
use App\Domain\Identity\Administrator;
use App\Domain\Identity\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\Support\Browser;

// Issue #26, decision 0012, spec sections 28 and 35 (non-negotiable 6):
// every API Resource carries `abilities`, a map from ability name to
// boolean, computed by the same Gate and policy that guard the endpoints.
// A new resource adds its row to RESOURCE_ABILITIES and its endpoints to
// ENDPOINTS.

uses(RefreshDatabase::class);

// The policy's method for each key of `abilities`.
const REPORTED = ['view' => 'view', 'update' => 'update', 'manage_two_factor' => 'manageTwoFactor', 'change_password' => 'changePassword'];

// The endpoints each ability guards, relative to the realm's prefix. An
// ability without an endpoint yet (`update`) is only compared with the policy.
const ENDPOINTS = [
    'view' => [['GET', '/auth/user']],
    'manage_two_factor' => [
        ['POST', '/auth/two-factor'],
        ['POST', '/auth/two-factor/confirm'],
        ['POST', '/auth/two-factor/recovery-codes'],
        ['POST', '/auth/two-factor/disable'],
    ],
    'change_password' => [['POST', '/auth/password']],
];

dataset('accounts', [
    'owner' => [Browser::ADMIN, '/api/v1/admin', fn (): Administrator => Administrator::factory()->withTwoFactor()->withRoles(Role::Owner)->create()],
    'read-only administrator' => [Browser::ADMIN, '/api/v1/admin', fn (): Administrator => Administrator::factory()->withTwoFactor()->withRoles(Role::ReadOnly)->create()],
    'administrator without a role' => [Browser::ADMIN, '/api/v1/admin', fn (): Administrator => Administrator::factory()->withTwoFactor()->create()],
    'customer' => [Browser::PORTAL, '/api/v1/customer', fn (): User => User::factory()->create()],
]);

beforeEach(function (): void {
    $this->freezeTime();
});

it('reports the abilities of the current user', function (string $origin, string $prefix, Administrator|User $account): void {
    $browser = new Browser($this);
    $browser->signIn($origin, $account);

    $abilities = $browser->get($origin, $prefix.'/auth/user')->assertOk()->json('data.abilities');

    expect($abilities)->toBeArray()
        ->and(array_keys($abilities))->toBe(array_keys(REPORTED));

    foreach ($abilities as $value) {
        expect($value)->toBeBool();
    }
})->with('accounts');

it('reports what the role allows', function (string $origin, string $prefix, Administrator|User $account, array $expected): void {
    $browser = new Browser($this);
    $browser->signIn($origin, $account);

    $browser->get($origin, $prefix.'/auth/user')->assertOk()->assertJsonPath('data.abilities', $expected);
})->with([
    'owner' => [
        Browser::ADMIN, '/api/v1/admin',
        fn (): Administrator => Administrator::factory()->withTwoFactor()->withRoles(Role::Owner)->create(),
        ['view' => true, 'update' => true, 'manage_two_factor' => true, 'change_password' => true],
    ],
    'read-only administrator' => [
        Browser::ADMIN, '/api/v1/admin',
        fn (): Administrator => Administrator::factory()->withTwoFactor()->withRoles(Role::ReadOnly)->create(),
        ['view' => true, 'update' => false, 'manage_two_factor' => true, 'change_password' => true],
    ],
    'administrator without a role' => [
        Browser::ADMIN, '/api/v1/admin',
        fn (): Administrator => Administrator::factory()->withTwoFactor()->create(),
        ['view' => true, 'update' => false, 'manage_two_factor' => true, 'change_password' => true],
    ],
    'customer' => [
        Browser::PORTAL, '/api/v1/customer',
        fn (): User => User::factory()->create(),
        ['view' => true, 'update' => true, 'manage_two_factor' => true, 'change_password' => true],
    ],
]);

it('reports the abilities with the login response as well', function (string $origin, string $prefix, Administrator|User $account): void {
    $response = (new Browser($this))->logIn($origin, $account->email)->assertOk();

    foreach (REPORTED as $key => $ability) {
        $response->assertJsonPath("data.abilities.$key", Gate::forUser($account)->allows($ability, $account));
    }
})->with('accounts');

it('reports exactly what the policy decides', function (string $origin, string $prefix, Administrator|User $account): void {
    $browser = new Browser($this);
    $browser->signIn($origin, $account);

    $abilities = $browser->get($origin, $prefix.'/auth/user')->assertOk()->json('data.abilities');

    foreach (REPORTED as $key => $ability) {
        expect($abilities[$key])->toBe(Gate::forUser($account)->allows($ability, $account), $key);
    }
})->with('accounts');

it('names an ability after its policy method, in snake case', function (): void {
    foreach (REPORTED as $key => $ability) {
        expect($key)->toBe(Str::snake($ability));
    }
});

it('answers 403 on an endpoint exactly when the ability is false', function (string $origin, string $prefix, Administrator|User $account, bool $denyEverything): void {
    $this->withoutMiddleware(ThrottleRequests::class);

    $browser = new Browser($this);
    $browser->signIn($origin, $account);

    if ($denyEverything) {
        // No policy says no to an account about itself, so the false half
        // of the contract is produced here. The login response is the one
        // place that reports abilities without being guarded by them.
        Gate::before(fn (): bool => false);
        $abilities = $browser->logIn($origin, $account->email)->assertOk()->json('data.abilities');
        $this->travel(30)->seconds();
        $browser->post($origin, $prefix.'/auth/two-factor/challenge', ['code' => Browser::code()]);
    } else {
        $abilities = $browser->get($origin, $prefix.'/auth/user')->assertOk()->json('data.abilities');
    }

    expect(array_keys($abilities))->toBe(array_keys(REPORTED));

    foreach (ENDPOINTS as $key => $calls) {
        expect($abilities[$key])->toBe(! $denyEverything, $key);

        foreach ($calls as [$method, $uri]) {
            $status = $browser->request($method, $origin, $prefix.$uri)->getStatusCode();

            expect($status === 403)->toBe(! $abilities[$key], "$method $uri answered $status while `$key` is ".json_encode($abilities[$key]));
        }
    }
})->with('accounts')->with(['as the policies decide' => false, 'when every check is denied' => true]);

it('covers every reported ability that has an endpoint', function (): void {
    expect(array_diff(array_keys(ENDPOINTS), array_keys(REPORTED)))->toBe([])
        // `update` gets its endpoint with the issue that adds one.
        ->and(array_values(array_diff(array_keys(REPORTED), array_keys(ENDPOINTS))))->toBe(['update']);
});

it('reports the abilities of an audit event as its policy decides', function (Role $role): void {
    $administrator = Administrator::factory()->withTwoFactor()->withRoles($role)->create();
    $browser = new Browser($this);
    $browser->signIn(Browser::ADMIN, $administrator);

    $events = $browser->get(Browser::ADMIN, '/api/v1/admin/audit-events')->assertOk()->json('data');

    expect($events)->not->toBeEmpty();

    foreach ($events as $event) {
        expect($event['abilities'])->toBe([
            'view' => Gate::forUser($administrator)->allows('view', new AuditEventRecord),
        ]);
    }
})->with(['owner' => [Role::Owner], 'read-only' => [Role::ReadOnly]]);

it('reports the abilities of branding as its policy decides', function (Role $role): void {
    $administrator = Administrator::factory()->withTwoFactor()->withRoles($role)->create();
    $browser = new Browser($this);
    $browser->signIn(Browser::ADMIN, $administrator);

    $abilities = $browser->get(Browser::ADMIN, '/api/v1/admin/branding')->assertOk()->json('data.abilities');

    expect($abilities)->toBe([
        'view' => Gate::forUser($administrator)->allows('view', new BrandingRecord),
        'update' => Gate::forUser($administrator)->allows('update', new BrandingRecord),
    ]);
})->with(['owner' => [Role::Owner], 'read-only' => [Role::ReadOnly]]);

it('answers 403 on saving branding exactly when `update` is false', function (Role $role): void {
    $administrator = Administrator::factory()->withTwoFactor()->withRoles($role)->create();
    $browser = new Browser($this);
    $browser->signIn(Browser::ADMIN, $administrator);

    $abilities = $browser->get(Browser::ADMIN, '/api/v1/admin/branding')->assertOk()->json('data.abilities');
    $status = $browser->put(Browser::ADMIN, '/api/v1/admin/branding', [
        'product_name' => 'Acme Hosting',
        'colors' => [
            'light' => ['primary' => null, 'primary_foreground' => null],
            'dark' => ['primary' => null, 'primary_foreground' => null],
        ],
        'links' => ['support' => null, 'terms' => null, 'privacy' => null],
    ])->getStatusCode();

    expect($status === 403)->toBe(! $abilities['update'], "PUT answered $status while `update` is ".json_encode($abilities['update']));
})->with(['owner' => [Role::Owner], 'read-only' => [Role::ReadOnly]]);

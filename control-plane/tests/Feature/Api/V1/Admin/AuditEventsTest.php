<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Application\Commands\AssignAdministratorRole;
use App\Domain\Audit\ActorType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditLog;
use App\Domain\Authorization\Role;
use App\Domain\Identity\Administrator;
use App\Domain\Identity\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AuditTrail;
use Tests\Support\Browser;

// Issue #27, decision 0013: the audit log is read in the admin realm only,
// newest first, cursor paginated, filtered by actor, resource, action and
// time, and guarded by a policy on a dedicated permission.

uses(RefreshDatabase::class);

const AUDIT_EVENTS = '/api/v1/admin/audit-events';

beforeEach(function (): void {
    $this->freezeTime();
});

/**
 * An administrator with the role, fully signed in. Signing in writes two
 * events of its own: `auth.login.succeeded`, then
 * `auth.two_factor.challenge_passed`.
 *
 * @return array{Administrator, Browser}
 */
function auditReader(mixed $test, ?Role $role = Role::Owner): array
{
    $factory = Administrator::factory()->withTwoFactor();
    $administrator = ($role === null ? $factory : $factory->withRoles($role))->create();

    $browser = new Browser($test);
    $browser->signIn(Browser::ADMIN, $administrator);

    return [$administrator, $browser];
}

function recordFor(Administrator|User $account, AuditAction $action): void
{
    app(AuditLog::class)->record(AuditEvent::onOwnAccount($account, $action));
}

/** An event with a time of its own. The clock is not moved: that would end the reader's session. */
function recordAt(Administrator $account, AuditAction $action, string $at): void
{
    app(AuditLog::class)->record(new AuditEvent(
        ActorType::Administrator,
        $account->id,
        $action,
        'administrator',
        $account->id,
        [],
        CarbonImmutable::parse($at, 'UTC'),
    ));
}

it('lists the events, newest first, with identifiers and names resolved now', function (): void {
    [$owner, $browser] = auditReader($this);

    $response = $browser->get(Browser::ADMIN, AUDIT_EVENTS)->assertOk();
    $stored = AuditTrail::all();

    $response->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.action', 'auth.two_factor.challenge_passed')
        ->assertJsonPath('data.1', [
            'id' => $stored[0]->id,
            'action' => 'auth.login.succeeded',
            'actor' => ['type' => 'administrator', 'id' => $owner->id, 'name' => $owner->name],
            'resource' => ['type' => 'administrator', 'id' => $owner->id, 'name' => $owner->name],
            'metadata' => [],
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Symfony',
            'request_id' => $stored[0]->request_id,
            'created_at' => CarbonImmutable::parse($stored[0]->created_at)->toIso8601String(),
            'abilities' => ['view' => true],
        ]);

    // An object, also when empty.
    expect($response->getContent())->toContain('"metadata":{}');
});

it('lets a read-only administrator read the log', function (): void {
    [, $browser] = auditReader($this, Role::ReadOnly);

    $browser->get(Browser::ADMIN, AUDIT_EVENTS)->assertOk()->assertJsonCount(2, 'data');
});

it('refuses an administrator without a role', function (): void {
    [, $browser] = auditReader($this, null);

    $browser->get(Browser::ADMIN, AUDIT_EVENTS)
        ->assertForbidden()
        ->assertJsonPath('message', 'This action is unauthorized.')
        // Not the 403 of the second-factor gate.
        ->assertJsonMissingPath('code');
});

it('refuses before it validates', function (): void {
    [, $browser] = auditReader($this, null);

    $browser->get(Browser::ADMIN, AUDIT_EVENTS.'?action=not-an-action')->assertForbidden();
});

it('refuses a guest and a session without the second factor', function (): void {
    (new Browser($this))->get(Browser::ADMIN, AUDIT_EVENTS)->assertUnauthorized();

    $owner = Administrator::factory()->withTwoFactor()->withRoles(Role::Owner)->create();
    $browser = new Browser($this);
    $browser->logIn(Browser::ADMIN, $owner->email)->assertOk();

    $browser->get(Browser::ADMIN, AUDIT_EVENTS)->assertForbidden()->assertJsonPath('code', 'two_factor_required');
});

it('does not exist in the customer realm', function (): void {
    $browser = new Browser($this);
    $browser->signIn(Browser::PORTAL, User::factory()->create());

    $browser->get(Browser::PORTAL, '/api/v1/customer/audit-events')->assertNotFound();
    $browser->get(Browser::PORTAL, AUDIT_EVENTS)->assertForbidden();
});

it('writes no event when the log is read', function (): void {
    [, $browser] = auditReader($this);

    $browser->get(Browser::ADMIN, AUDIT_EVENTS)->assertOk();
    $browser->get(Browser::ADMIN, AUDIT_EVENTS)->assertOk();

    expect(AuditTrail::all())->toHaveCount(2);
});

it('filters by actor, resource and action', function (): void {
    [$owner, $browser] = auditReader($this);
    $other = Administrator::factory()->create();
    $user = User::factory()->create();

    recordFor($other, AuditAction::LoggedOut);
    recordFor($user, AuditAction::LoggedOut);
    app(AssignAdministratorRole::class)->handle($owner, $other, Role::ReadOnly);
    app(AssignAdministratorRole::class)->handle(null, $other, Role::Owner);

    $actions = fn (string $query): array => $browser->get(Browser::ADMIN, AUDIT_EVENTS.'?'.$query)->assertOk()->json('data.*.action');

    expect($actions('action=auth.logout'))->toBe(['auth.logout', 'auth.logout'])
        ->and($actions('actor_type=user'))->toBe(['auth.logout'])
        ->and($actions('actor_type=system'))->toBe(['administrator.role_assigned'])
        ->and($actions('actor_id='.$owner->id))->toBe(['administrator.role_assigned', 'auth.two_factor.challenge_passed', 'auth.login.succeeded'])
        ->and($actions('resource=administrator&resource_id='.$other->id))->toBe(['administrator.role_assigned', 'administrator.role_assigned', 'auth.logout'])
        ->and($actions('resource=user'))->toBe(['auth.logout'])
        ->and($actions('actor_id='.$owner->id.'&action=administrator.role_assigned'))->toBe(['administrator.role_assigned'])
        ->and($actions('actor_id='.$user->id.'&actor_type=administrator'))->toBe([]);
});

it('filters by time, both ends included', function (): void {
    [$owner, $browser] = auditReader($this);

    recordAt($owner, AuditAction::LoggedOut, '2030-01-01 10:00:00');
    recordAt($owner, AuditAction::TwoFactorDisabled, '2030-01-01 11:00:00');
    recordAt($owner, AuditAction::RecoveryCodesRegenerated, '2030-01-01 12:00:00');

    $actions = fn (string $query): array => $browser->get(Browser::ADMIN, AUDIT_EVENTS.'?'.$query)->assertOk()->json('data.*.action');

    expect($actions('from=2030-01-01T11:00:00Z'))->toBe(['auth.two_factor.recovery_codes_regenerated', 'auth.two_factor.disabled'])
        ->and($actions('from=2030-01-01T10:00:00Z&to=2030-01-01T11:00:00Z'))->toBe(['auth.two_factor.disabled', 'auth.logout'])
        ->and($actions('from=2030-01-01T10:30:00Z&to=2030-01-01T11:30:00Z'))->toBe(['auth.two_factor.disabled'])
        ->and($actions('from=2031-01-01T00:00:00Z'))->toBe([])
        // `to` on its own: everything up to then, the reader's sign-in included.
        ->and($actions('to=2030-01-01T10:00:00Z'))->toBe(['auth.logout', 'auth.two_factor.challenge_passed', 'auth.login.succeeded']);
});

it('rejects a filter it does not understand', function (string $query, string $field): void {
    [, $browser] = auditReader($this);

    $browser->get(Browser::ADMIN, AUDIT_EVENTS.'?'.$query)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
})->with([
    'unknown action' => ['action=website.deleted.maybe', 'action'],
    'unknown actor type' => ['actor_type=App%5CDomain%5CIdentity%5CUser', 'actor_type'],
    'actor ID that is no UUID' => ['actor_id=1', 'actor_id'],
    'resource ID that is no UUID' => ['resource_id=%27%20OR%201%3D1', 'resource_id'],
    'resource name too long' => ['resource='.str_repeat('a', 65), 'resource'],
    'from that is no date' => ['from=yesterday-ish', 'from'],
    'to before from' => ['from=2030-01-02&to=2030-01-01', 'to'],
    'page size zero' => ['per_page=0', 'per_page'],
    'page size too large' => ['per_page=101', 'per_page'],
]);

it('pages through the log with a cursor, without gaps or repeats', function (): void {
    [$owner, $browser] = auditReader($this);

    // All five events share one frozen instant: the order is the key's.
    foreach ([AuditAction::LoggedOut, AuditAction::LoginSucceeded, AuditAction::LoggedOut] as $action) {
        recordFor($owner, $action);
    }

    $expected = AuditTrail::all()->pluck('id')->reverse()->values()->all();
    $seen = [];
    $cursor = null;
    $pages = 0;

    do {
        $response = $browser->get(Browser::ADMIN, AUDIT_EVENTS.'?per_page=2'.($cursor === null ? '' : '&cursor='.urlencode($cursor)))->assertOk();

        expect($response->json('meta.per_page'))->toBe(2)
            ->and(count($response->json('data')))->toBeLessThanOrEqual(2);

        $seen = [...$seen, ...$response->json('data.*.id')];
        $cursor = $response->json('meta.next_cursor');
        $pages++;
    } while ($cursor !== null && $pages < 10);

    expect($expected)->toHaveCount(5)
        ->and($seen)->toBe($expected)
        ->and($pages)->toBe(3);
});

it('uses 50 as the page size when none is given', function (): void {
    [, $browser] = auditReader($this);

    $browser->get(Browser::ADMIN, AUDIT_EVENTS)->assertOk()->assertJsonPath('meta.per_page', 50);
});

it('answers the first page for a cursor it did not issue', function (string $cursor): void {
    [, $browser] = auditReader($this);

    $browser->get(Browser::ADMIN, AUDIT_EVENTS.'?cursor='.urlencode($cursor))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.action', 'auth.two_factor.challenge_passed');
})->with([
    'garbage' => ['not-a-cursor'],
    'no ID' => [base64_encode('{"created_at":"2030-01-01","_pointsToNextItems":true}')],
    'an ID that is no UUID' => [base64_encode('{"id":"1; DROP TABLE audit_events","_pointsToNextItems":true}')],
    'JSON that is no object' => [base64_encode('42')],
]);

it('still lists the events of an account that is gone, without a name', function (): void {
    [, $browser] = auditReader($this);
    $gone = Administrator::factory()->create();
    recordFor($gone, AuditAction::LoggedOut);
    $gone->delete();

    $browser->get(Browser::ADMIN, AUDIT_EVENTS.'?actor_id='.$gone->id)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.actor', ['type' => 'administrator', 'id' => $gone->id, 'name' => null])
        ->assertJsonPath('data.0.resource', ['type' => 'administrator', 'id' => $gone->id, 'name' => null]);
});

it('names a customer and gives the system and an unknown address no name', function (): void {
    [, $browser] = auditReader($this);
    $user = User::factory()->create();
    $target = Administrator::factory()->create();

    recordFor($user, AuditAction::LoggedOut);
    app(AssignAdministratorRole::class)->handle(null, $target, Role::ReadOnly);
    (new Browser($this))->logIn(Browser::PORTAL, 'nobody@example.com')->assertUnprocessable();

    $events = collect($browser->get(Browser::ADMIN, AUDIT_EVENTS)->assertOk()->json('data'))->keyBy('action');

    expect($events['auth.logout']['actor'])->toBe(['type' => 'user', 'id' => $user->id, 'name' => $user->name])
        ->and($events['administrator.role_assigned']['actor'])->toBe(['type' => 'system', 'id' => null, 'name' => null])
        ->and($events['administrator.role_assigned']['resource'])->toBe(['type' => 'administrator', 'id' => $target->id, 'name' => $target->name])
        ->and($events['administrator.role_assigned']['metadata'])->toBe(['role' => 'read-only'])
        ->and($events['auth.login.failed']['actor'])->toBe(['type' => 'user', 'id' => null, 'name' => null])
        ->and($events['auth.login.failed']['resource'])->toBe(['type' => 'user', 'id' => null, 'name' => null])
        ->and(array_keys($events['auth.login.failed']['metadata']))->toBe(['email_hash']);
});

it('never returns an email address', function (): void {
    [$owner, $browser] = auditReader($this);
    $user = User::factory()->create();
    recordFor($user, AuditAction::LoggedOut);

    $content = (string) $browser->get(Browser::ADMIN, AUDIT_EVENTS)->assertOk()->getContent();

    expect($content)->not->toContain($owner->email)->not->toContain($user->email);
});

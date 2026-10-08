<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Domain\Audit\ActorType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditEventRecord;
use App\Domain\Audit\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

// Decision 0013: append-only in the database. A trigger rejects UPDATE,
// DELETE and TRUNCATE, with two exceptions it encodes itself: clearing
// `ip_address` and `user_agent`, and deleting rows older than 12 months.

uses(RefreshDatabase::class);

function storeAuditEvent(?CarbonImmutable $at = null): string
{
    app(AuditLog::class)->record(new AuditEvent(
        ActorType::System,
        null,
        AuditAction::AdministratorRoleAssigned,
        'administrator',
        '0199c1d2-0000-7000-8000-000000000001',
        ['role' => 'owner'],
        $at ?? CarbonImmutable::now(),
        '203.0.113.7',
        'Firefox',
        '0199c1d2-0000-7000-8000-000000000002',
    ));

    return (string) DB::table('audit_events')->orderByDesc('id')->value('id');
}

function refusedByTheDatabase(Closure $statement): void
{
    expect(fn () => DB::transaction($statement))->toThrow(QueryException::class, 'append-only');
}

it('refuses to change a stored event', function (string $column, mixed $value): void {
    $id = storeAuditEvent();

    refusedByTheDatabase(fn () => DB::table('audit_events')->where('id', $id)->update([$column => $value]));

    expect(DB::table('audit_events')->where('id', $id)->value($column))->not->toBe($value);
})->with([
    'actor type' => ['actor_type', 'administrator'],
    'actor' => ['actor_id', '0199c1d2-0000-7000-8000-00000000000a'],
    'action' => ['action', 'auth.logout'],
    'resource' => ['resource', 'user'],
    'resource ID' => ['resource_id', '0199c1d2-0000-7000-8000-00000000000b'],
    'metadata' => ['metadata', '{"role":"read-only"}'],
    'request ID' => ['request_id', '0199c1d2-0000-7000-8000-00000000000c'],
    'time' => ['created_at', '2020-01-01 00:00:00+00'],
    'another IP address' => ['ip_address', '198.51.100.1'],
    'another user agent' => ['user_agent', 'Chrome'],
]);

it('refuses to delete a recent event', function (): void {
    $id = storeAuditEvent();

    refusedByTheDatabase(fn () => DB::table('audit_events')->where('id', $id)->delete());

    expect(DB::table('audit_events')->count())->toBe(1);
});

it('refuses to delete an event that is 11 months old', function (): void {
    $id = storeAuditEvent(CarbonImmutable::now()->subMonths(11));

    refusedByTheDatabase(fn () => DB::table('audit_events')->where('id', $id)->delete());
});

it('refuses to empty the table', function (): void {
    storeAuditEvent();

    refusedByTheDatabase(fn () => DB::statement('TRUNCATE audit_events'));

    expect(DB::table('audit_events')->count())->toBe(1);
});

it('refuses changes made through the model as well', function (): void {
    storeAuditEvent();
    $record = AuditEventRecord::query()->sole();

    refusedByTheDatabase(fn () => $record->forceFill(['action' => 'auth.logout'])->save());
    refusedByTheDatabase(fn () => $record->delete());

    expect(DB::table('audit_events')->value('action'))->toBe('administrator.role_assigned');
});

it('allows clearing the IP address and the user agent', function (array $cleared): void {
    $id = storeAuditEvent();

    DB::table('audit_events')->where('id', $id)->update($cleared);

    $row = DB::table('audit_events')->where('id', $id)->sole();

    foreach (array_keys($cleared) as $column) {
        expect($row->{$column})->toBeNull();
    }

    expect($row->action)->toBe('administrator.role_assigned');
})->with([
    'both' => [['ip_address' => null, 'user_agent' => null]],
    'the IP address' => [['ip_address' => null]],
    'the user agent' => [['user_agent' => null]],
]);

it('allows deleting an event older than 12 months', function (): void {
    $old = storeAuditEvent(CarbonImmutable::now()->subMonths(13));
    $recent = storeAuditEvent();

    DB::table('audit_events')->where('id', $old)->delete();

    expect(DB::table('audit_events')->pluck('id')->all())->toBe([$recent]);
});

<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Domain\Audit\ActorType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditEventRecord;
use App\Domain\Audit\AuditLog;
use App\Domain\Identity\Administrator;
use App\Domain\Identity\User;
use App\Infrastructure\Audit\DatabaseAuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AuditTrail;

// Decision 0013: one plain table behind an interface.

uses(RefreshDatabase::class);

it('is the audit log of the application', function (): void {
    expect(app(AuditLog::class))->toBeInstanceOf(DatabaseAuditLog::class);
});

it('stores every field of an event', function (): void {
    $administrator = Administrator::factory()->create();
    $target = Administrator::factory()->create();

    app(AuditLog::class)->record(new AuditEvent(
        ActorType::Administrator,
        $administrator->id,
        AuditAction::AdministratorRoleAssigned,
        'administrator',
        $target->id,
        ['role' => 'owner'],
        CarbonImmutable::parse('2026-10-08 12:00:00', 'UTC'),
        '203.0.113.7',
        'Firefox',
        '0199c1d2-0000-7000-8000-000000000002',
    ));

    $row = AuditTrail::last();

    expect(Str::isUuid($row->id))->toBeTrue()
        ->and($row->actor_type)->toBe('administrator')
        ->and($row->actor_id)->toBe($administrator->id)
        ->and($row->action)->toBe('administrator.role_assigned')
        ->and($row->resource)->toBe('administrator')
        ->and($row->resource_id)->toBe($target->id)
        ->and(AuditTrail::metadata($row))->toBe(['role' => 'owner'])
        ->and($row->ip_address)->toBe('203.0.113.7')
        ->and($row->user_agent)->toBe('Firefox')
        ->and($row->request_id)->toBe('0199c1d2-0000-7000-8000-000000000002')
        ->and(CarbonImmutable::parse($row->created_at)->equalTo('2026-10-08 12:00:00 UTC'))->toBeTrue();
});

it('stores an event without an actor, a resource ID or an origin', function (): void {
    app(AuditLog::class)->record(new AuditEvent(
        ActorType::User, null, AuditAction::LoginFailed, 'user', null, [], CarbonImmutable::now(),
    ));

    $row = AuditTrail::last();

    expect($row->actor_id)->toBeNull()
        ->and($row->resource_id)->toBeNull()
        ->and($row->ip_address)->toBeNull()
        ->and($row->user_agent)->toBeNull()
        ->and($row->request_id)->toBeNull();
});

it('stores empty metadata as an object', function (): void {
    app(AuditLog::class)->record(AuditEvent::onOwnAccount(User::factory()->create(), AuditAction::LoggedOut));

    expect(DB::scalar('select jsonb_typeof(metadata) from audit_events'))->toBe('object');
});

it('describes what an account did to itself with identifiers only', function (): void {
    $this->freezeTime();
    $user = User::factory()->create();

    $event = AuditEvent::onOwnAccount($user, AuditAction::LoggedOut);

    expect($event->actorType)->toBe(ActorType::User)
        ->and($event->actorId)->toBe($user->id)
        ->and($event->resource)->toBe('user')
        ->and($event->resourceId)->toBe($user->id)
        ->and($event->metadata)->toBe([])
        ->and($event->occurredAt->equalTo(now()))->toBeTrue();
});

it('gives events IDs that sort in the order they happened, also within one instant', function (): void {
    $this->freezeTime();
    $user = User::factory()->create();
    $log = app(AuditLog::class);

    $log->record(AuditEvent::onOwnAccount($user, AuditAction::LoginSucceeded));
    $log->record(AuditEvent::onOwnAccount($user, AuditAction::TwoFactorEnrollmentStarted));
    $this->travel(1)->seconds();
    $log->record(AuditEvent::onOwnAccount($user, AuditAction::TwoFactorConfirmed));
    $log->record(AuditEvent::onOwnAccount($user, AuditAction::LoggedOut));

    expect(AuditTrail::actions())->toBe([
        'auth.login.succeeded',
        'auth.two_factor.enrollment_started',
        'auth.two_factor.confirmed',
        'auth.logout',
    ]);
});

it('reads a stored event through the model', function (): void {
    $user = User::factory()->create();
    app(AuditLog::class)->record(AuditEvent::onOwnAccount($user, AuditAction::RecoveryCodeUsed, ['remaining' => 3]));

    $record = AuditEventRecord::query()->sole();

    expect($record->action)->toBe('auth.two_factor.recovery_code_used')
        ->and($record->metadata)->toBe(['remaining' => 3])
        ->and($record->created_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($record->actorName)->toBeNull()
        ->and($record->resourceName)->toBeNull();
});

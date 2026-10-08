<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Domain\Audit\ActorType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEvent;
use Carbon\CarbonImmutable;

// Decision 0013: an event holds identifiers and short stable names only.

it('names an actor type with a short stable name, never a class name', function (): void {
    expect(array_map(fn (ActorType $type): string => $type->value, ActorType::cases()))
        ->toBe(['administrator', 'user', 'node', 'system']);
});

it('names an action in lower case with dots', function (): void {
    expect(AuditAction::cases())->toHaveCount(12);

    foreach (AuditAction::cases() as $action) {
        expect($action->value)->toMatch('/^[a-z_]+(\.[a-z_]+)+$/')
            ->and(strlen($action->value))->toBeLessThanOrEqual(64);
    }
});

it('adds where the request came from without changing what happened', function (): void {
    $event = new AuditEvent(
        ActorType::System,
        null,
        AuditAction::AdministratorRoleAssigned,
        'administrator',
        '0199c1d2-0000-7000-8000-000000000001',
        ['role' => 'owner'],
        CarbonImmutable::parse('2026-10-08 12:00:00'),
    );

    $placed = $event->from('203.0.113.7', 'Firefox', '0199c1d2-0000-7000-8000-000000000002');

    expect($event->ipAddress)->toBeNull()
        ->and($event->userAgent)->toBeNull()
        ->and($event->requestId)->toBeNull()
        ->and($placed->ipAddress)->toBe('203.0.113.7')
        ->and($placed->userAgent)->toBe('Firefox')
        ->and($placed->requestId)->toBe('0199c1d2-0000-7000-8000-000000000002')
        ->and($placed->actorType)->toBe(ActorType::System)
        ->and($placed->action)->toBe(AuditAction::AdministratorRoleAssigned)
        ->and($placed->resource)->toBe('administrator')
        ->and($placed->resourceId)->toBe('0199c1d2-0000-7000-8000-000000000001')
        ->and($placed->metadata)->toBe(['role' => 'owner'])
        ->and($placed->occurredAt->equalTo($event->occurredAt))->toBeTrue();
});

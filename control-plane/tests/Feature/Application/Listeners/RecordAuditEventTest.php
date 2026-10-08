<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Application\Services\AuditContext;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Fixtures\SomethingAudited;
use Tests\Support\AuditTrail;
use Tests\Support\Browser;
use Tests\Support\InMemoryAuditLog;

// Issue #27: an event that implements Audited is written to the audit log
// by one synchronous listener, together with where the request came from.

uses(RefreshDatabase::class);

it('records a dispatched event that says how it is audited', function (): void {
    $log = new InMemoryAuditLog;
    $this->app->instance(AuditLog::class, $log);

    event(new SomethingAudited);

    expect($log->events)->toHaveCount(1)
        ->and($log->events[0]->action)->toBe(AuditAction::AdministratorRoleAssigned)
        ->and($log->events[0]->metadata)->toBe(['role' => 'owner']);
});

it('adds where the request came from', function (): void {
    $log = new InMemoryAuditLog;
    $this->app->instance(AuditLog::class, $log);
    app(AuditContext::class)->capture('203.0.113.7', 'Firefox', '0199c1d2-0000-7000-8000-000000000002');

    event(new SomethingAudited);

    expect($log->events[0]->ipAddress)->toBe('203.0.113.7')
        ->and($log->events[0]->userAgent)->toBe('Firefox')
        ->and($log->events[0]->requestId)->toBe('0199c1d2-0000-7000-8000-000000000002');
});

it('records an event outside a request without an origin', function (): void {
    $log = new InMemoryAuditLog;
    $this->app->instance(AuditLog::class, $log);

    event(new SomethingAudited);

    expect($log->events[0]->ipAddress)->toBeNull()
        ->and($log->events[0]->userAgent)->toBeNull()
        ->and($log->events[0]->requestId)->toBeNull();
});

it('writes to the database by default', function (): void {
    event(new SomethingAudited);

    expect(AuditTrail::actions())->toBe(['administrator.role_assigned']);
});

it('lets a failed write reach whoever dispatched the event', function (): void {
    $this->app->instance(AuditLog::class, InMemoryAuditLog::failing());

    expect(fn () => event(new SomethingAudited))
        ->toThrow(RuntimeException::class, 'The audit log cannot be written.');
});

it('answers every API request with a request ID', function (): void {
    $first = $this->getJson('/api/v1/health')->assertOk()->headers->get('X-Request-Id');
    $second = $this->getJson('/api/v1/health')->assertOk()->headers->get('X-Request-Id');

    expect(Str::isUuid($first))->toBeTrue()
        ->and(Str::isUuid($second))->toBeTrue()
        ->and($second)->not->toBe($first);
});

it('answers a refused request with a request ID as well', function (): void {
    $response = (new Browser($this))->get('https://elsewhere.example', '/api/v1/admin/auth/user')->assertForbidden();

    expect(Str::isUuid($response->headers->get('X-Request-Id')))->toBeTrue();
});

<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Application\Commands\DispatchOneShotJob;
use App\Application\Jobs\ClearAuditAddresses;
use App\Application\Jobs\OneShotJob;
use App\Application\Jobs\PruneAuditEvents;
use App\Domain\Audit\RetentionPolicy;
use App\Domain\Provisioning\JobHistoryEntry;
use App\Domain\Provisioning\JobStatus;
use App\Domain\Provisioning\JobType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Spec section 21 and decision 0013: events are kept for 24 months and
// ip_address and user_agent for 90 days, applied by scheduled jobs that
// leave their rows in job_history. The trigger refuses to delete an event
// younger than 12 months, so a shorter setting must never reach it.
//
// The cutoffs are PostgreSQL's now(), as in the trigger. Inside the test's
// transaction now() does not move, so a minute either side is exact.

uses(RefreshDatabase::class);

/** Writes an event of the given age, as a PostgreSQL interval. Returns its ID. */
function auditEventAged(string $age): string
{
    $id = (string) Str::uuid7();

    DB::table('audit_events')->insert([
        'id' => $id,
        'actor_type' => 'administrator',
        'actor_id' => (string) Str::uuid7(),
        'action' => 'administrator.logged_in',
        'resource' => 'administrator',
        'resource_id' => (string) Str::uuid7(),
        'metadata' => '{"field":"changed"}',
        'ip_address' => '203.0.113.7',
        'user_agent' => 'Mozilla/5.0',
        'request_id' => (string) Str::uuid7(),
        'created_at' => DB::raw("now() - interval '$age'"),
    ]);

    return $id;
}

/** @return list<string> */
function remainingAuditEvents(): array
{
    /** @var list<string> */
    return DB::table('audit_events')->orderBy('id')->pluck('id')->all();
}

/** @param  class-string<OneShotJob>  $job */
function runRetentionJob(string $job): JobHistoryEntry
{
    $opened = app(DispatchOneShotJob::class)->handle($job, RetentionPolicy::RESOURCE_TYPE, RetentionPolicy::RESOURCE_ID);

    return JobHistoryEntry::query()->findOrFail($opened->id);
}

describe('prune_audit_events', function (): void {
    it('is called prune_audit_events in the history', function (): void {
        expect(PruneAuditEvents::type())->toBe(JobType::PruneAuditEvents)
            ->and(PruneAuditEvents::type()->value)->toBe('prune_audit_events');
    });

    it('deletes events older than 24 months by default', function (): void {
        $expired = auditEventAged('24 months 1 minute');
        $kept = auditEventAged('24 months -1 minute');
        $recent = auditEventAged('1 day');

        runRetentionJob(PruneAuditEvents::class);

        expect(remainingAuditEvents())->toEqualCanonicalizing([$kept, $recent])
            ->and(remainingAuditEvents())->not->toContain($expired);
    });

    it('follows a configured retention', function (): void {
        config(['hostmesh.audit.retention.event_months' => 18]);

        auditEventAged('18 months 1 minute');
        $kept = auditEventAged('18 months -1 minute');

        runRetentionJob(PruneAuditEvents::class);

        expect(remainingAuditEvents())->toBe([$kept]);
    });

    it('never deletes an event younger than the floor of 12 months', function (): void {
        config(['hostmesh.audit.retention.event_months' => 6]);

        auditEventAged('12 months 1 minute');
        $kept = auditEventAged('12 months -1 minute');
        $alsoKept = auditEventAged('7 months');

        $entry = runRetentionJob(PruneAuditEvents::class);

        expect($entry->status)->toBe(JobStatus::Succeeded)
            ->and(remainingAuditEvents())->toEqualCanonicalizing([$kept, $alsoKept]);
    });

    it('changes nothing when it runs again', function (): void {
        auditEventAged('25 months');
        $kept = auditEventAged('1 month');

        runRetentionJob(PruneAuditEvents::class);
        $again = runRetentionJob(PruneAuditEvents::class);

        expect($again->status)->toBe(JobStatus::Succeeded)
            ->and(remainingAuditEvents())->toBe([$kept]);
    });

    it('leaves a succeeded row for the audit log in job_history', function (): void {
        $entry = runRetentionJob(PruneAuditEvents::class);

        expect($entry->type)->toBe(JobType::PruneAuditEvents)
            ->and($entry->resource_type)->toBe('audit_log')
            ->and($entry->resource_id)->toBe(RetentionPolicy::RESOURCE_ID)
            ->and($entry->generation)->toBeNull()
            ->and($entry->node_id)->toBeNull()
            ->and($entry->status)->toBe(JobStatus::Succeeded)
            ->and($entry->attempts)->toBe(0);
    });
});

describe('clear_audit_addresses', function (): void {
    it('is called clear_audit_addresses in the history', function (): void {
        expect(ClearAuditAddresses::type())->toBe(JobType::ClearAuditAddresses)
            ->and(ClearAuditAddresses::type()->value)->toBe('clear_audit_addresses');
    });

    it('clears ip_address and user_agent after 90 days by default', function (): void {
        $expired = auditEventAged('90 days 1 minute');
        $kept = auditEventAged('90 days -1 minute');

        runRetentionJob(ClearAuditAddresses::class);

        $rows = DB::table('audit_events')->get()->keyBy('id');

        expect($rows[$expired]->ip_address)->toBeNull()
            ->and($rows[$expired]->user_agent)->toBeNull()
            ->and($rows[$kept]->ip_address)->toBe('203.0.113.7')
            ->and($rows[$kept]->user_agent)->toBe('Mozilla/5.0');
    });

    it('follows a configured period', function (): void {
        config(['hostmesh.audit.retention.address_days' => 30]);

        $expired = auditEventAged('30 days 1 minute');
        $kept = auditEventAged('30 days -1 minute');

        runRetentionJob(ClearAuditAddresses::class);

        $rows = DB::table('audit_events')->get()->keyBy('id');

        expect($rows[$expired]->ip_address)->toBeNull()
            ->and($rows[$kept]->ip_address)->toBe('203.0.113.7');
    });

    it('clears both columns of an event that has only one of them', function (): void {
        $id = auditEventAged('100 days');
        DB::table('audit_events')->where('id', $id)->update(['ip_address' => null]);

        runRetentionJob(ClearAuditAddresses::class);

        expect(DB::table('audit_events')->where('id', $id)->value('user_agent'))->toBeNull();
    });

    it('changes no other column and deletes nothing', function (): void {
        $id = auditEventAged('25 months');
        $before = (array) DB::table('audit_events')->where('id', $id)->sole();

        runRetentionJob(ClearAuditAddresses::class);

        $after = (array) DB::table('audit_events')->where('id', $id)->sole();

        expect($after)->toBe([...$before, 'ip_address' => null, 'user_agent' => null]);
    });

    it('leaves a succeeded row for the audit log in job_history', function (): void {
        $entry = runRetentionJob(ClearAuditAddresses::class);

        expect($entry->type)->toBe(JobType::ClearAuditAddresses)
            ->and($entry->resource_type)->toBe('audit_log')
            ->and($entry->resource_id)->toBe(RetentionPolicy::RESOURCE_ID)
            ->and($entry->status)->toBe(JobStatus::Succeeded);
    });
});

describe('the schedule', function (): void {
    it('runs both jobs daily at 03:00 UTC', function (): void {
        $this->travelTo(now('UTC')->setTime(3, 0));

        $this->artisan('schedule:run')->assertSuccessful();

        $types = JobHistoryEntry::query()->get()
            ->map(fn (JobHistoryEntry $entry): array => [$entry->type, $entry->status, $entry->resource_id])
            ->all();

        expect($types)->toEqualCanonicalizing([
            [JobType::PruneAuditEvents, JobStatus::Succeeded, RetentionPolicy::RESOURCE_ID],
            [JobType::ClearAuditAddresses, JobStatus::Succeeded, RetentionPolicy::RESOURCE_ID],
        ]);
    });

    it('runs neither at any other time of day', function (): void {
        $this->travelTo(now('UTC')->setTime(4, 0));

        $this->artisan('schedule:run')->assertSuccessful();

        expect(JobHistoryEntry::query()->count())->toBe(0);
    });
});

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
use Illuminate\Database\Events\QueryExecuted;
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

// Issue #238: a long backlog must not become one long transaction. Each
// batch is a statement of its own, and a run that uses up its time hands
// the rest to a new run with a row of its own. A run time of 0 makes
// every run stop after its first batch.
describe('in batches', function (): void {
    /**
     * The statements of a kind that run inside $work, each with the
     * transaction level it ran at.
     *
     * @return list<int>
     */
    function retentionStatements(string $verb, Closure $work): array
    {
        $levels = [];

        DB::listen(function (QueryExecuted $query) use ($verb, &$levels): void {
            if (str_starts_with(ltrim($query->sql), $verb.' ')) {
                $levels[] = $query->connection->transactionLevel();
            }
        });

        $work();

        return $levels;
    }

    beforeEach(function (): void {
        config(['hostmesh.audit.retention.batch_size' => 2]);
    });

    it('deletes in batches of the configured size, none inside a transaction of its own making', function (): void {
        foreach (range(1, 5) as $ignored) {
            auditEventAged('25 months');
        }
        $kept = auditEventAged('1 month');
        $level = DB::transactionLevel();

        $statements = retentionStatements('DELETE', fn () => runRetentionJob(PruneAuditEvents::class));

        // 2, 2 and 1: a short batch means nothing is left.
        expect($statements)->toBe([$level, $level, $level])
            ->and(remainingAuditEvents())->toBe([$kept])
            ->and(JobHistoryEntry::query()->count())->toBe(1);
    });

    it('clears in batches of the configured size, none inside a transaction of its own making', function (): void {
        foreach (range(1, 5) as $ignored) {
            auditEventAged('100 days');
        }
        $kept = auditEventAged('1 day');
        $level = DB::transactionLevel();

        $statements = retentionStatements('UPDATE audit_events', fn () => runRetentionJob(ClearAuditAddresses::class));

        expect($statements)->toBe([$level, $level, $level])
            ->and(DB::table('audit_events')->whereNotNull('ip_address')->pluck('id')->all())->toBe([$kept])
            ->and(JobHistoryEntry::query()->count())->toBe(1);
    });

    it('hands the rest of a deletion to a new run when its time is up', function (): void {
        config(['hostmesh.audit.retention.run_seconds' => 0]);

        foreach (range(1, 5) as $ignored) {
            auditEventAged('25 months');
        }
        $kept = auditEventAged('1 month');

        runRetentionJob(PruneAuditEvents::class);

        $entries = JobHistoryEntry::query()->orderBy('id')->get();

        // 2, 2 and 1, one run each.
        expect(remainingAuditEvents())->toBe([$kept])
            ->and($entries)->toHaveCount(3)
            ->and($entries->pluck('type')->unique()->all())->toBe([JobType::PruneAuditEvents])
            ->and($entries->pluck('status')->unique()->all())->toBe([JobStatus::Succeeded])
            ->and($entries->pluck('resource_id')->unique()->all())->toBe([RetentionPolicy::RESOURCE_ID]);
    });

    it('hands the rest of a clearing to a new run when its time is up', function (): void {
        config(['hostmesh.audit.retention.run_seconds' => 0]);

        foreach (range(1, 4) as $ignored) {
            auditEventAged('100 days');
        }

        runRetentionJob(ClearAuditAddresses::class);

        $entries = JobHistoryEntry::query()->get();

        // 2 and 2, then a run that finds nothing left.
        expect(DB::table('audit_events')->whereNotNull('ip_address')->count())->toBe(0)
            ->and($entries)->toHaveCount(3)
            ->and($entries->pluck('type')->unique()->all())->toBe([JobType::ClearAuditAddresses])
            ->and($entries->pluck('status')->unique()->all())->toBe([JobStatus::Succeeded]);
    });

    it('starts no new run when it finished in time', function (): void {
        foreach (range(1, 4) as $ignored) {
            auditEventAged('25 months');
        }

        runRetentionJob(PruneAuditEvents::class);

        expect(remainingAuditEvents())->toBe([])
            ->and(JobHistoryEntry::query()->count())->toBe(1);
    });

    it('still never deletes an event younger than the floor', function (): void {
        config([
            'hostmesh.audit.retention.event_months' => 6,
            'hostmesh.audit.retention.run_seconds' => 0,
        ]);

        foreach (range(1, 3) as $ignored) {
            auditEventAged('13 months');
        }
        $kept = auditEventAged('12 months -1 minute');

        runRetentionJob(PruneAuditEvents::class);

        expect(remainingAuditEvents())->toBe([$kept])
            ->and(JobHistoryEntry::query()->pluck('status')->unique()->all())->toBe([JobStatus::Succeeded]);
    });
});

it('stops well inside the job timeout by default', function (): void {
    $runSeconds = config()->integer('hostmesh.audit.retention.run_seconds');

    expect(config()->integer('hostmesh.audit.retention.batch_size'))->toBe(10000)
        ->and($runSeconds)->toBe(40)
        ->and($runSeconds)->toBeLessThan((new PruneAuditEvents('unused'))->timeout)
        ->and($runSeconds)->toBeLessThan((new ClearAuditAddresses('unused'))->timeout);
});

// The clearing job looks for events that still hold an address. Without an
// index on exactly those, every batch reads past all the events cleared
// before it (measured in issue #238: 1.2 s per batch after 820,000).
it('indexes the events that still hold an address by their age', function (): void {
    $definition = DB::scalar("SELECT indexdef FROM pg_indexes WHERE tablename = 'audit_events' AND indexname = 'audit_events_addresses_created_at_index'");

    expect($definition)->toBe('CREATE INDEX audit_events_addresses_created_at_index ON public.audit_events USING btree (created_at) WHERE ((ip_address IS NOT NULL) OR (user_agent IS NOT NULL))');
});

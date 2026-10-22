<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Application\Exceptions\JobFailure;
use App\Application\Jobs\OneShotJobs;
use App\Application\Jobs\SweepJobHistory;
use App\Application\Services\JobHistorySweeper;
use App\Domain\Provisioning\JobHistoryEntry;
use App\Domain\Provisioning\JobStatus;
use App\Domain\Provisioning\JobType;
use App\Domain\Provisioning\SweepPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Fixtures\ScriptedJob;
use Tests\Support\QueueRunner;

// Issue #155 and decision 0014: Redis must never be needed for
// correctness. A row whose job Redis lost stays unfinished for good, so a
// scheduled sweep gives every unfinished row that nobody touched for 15
// minutes a new job. A new dispatch token in the row turns the old copy,
// if Redis still holds one, into a job that does nothing.

uses(RefreshDatabase::class);

beforeEach(function (): void {
    ScriptedJob::$script = [];
    ScriptedJob::$runs = 0;

    $this->travelTo(CarbonImmutable::parse('2026-10-22 12:00:00', 'UTC'));
});

/** Writes a row of retry_reconcile left untouched for the given seconds. Returns its ID. */
function jobRowUntouchedFor(int $seconds, JobStatus $status = JobStatus::Pending, int $attempts = 0): string
{
    $id = (string) Str::uuid7();
    $at = CarbonImmutable::now()->subSeconds($seconds);

    DB::table('job_history')->insert([
        'id' => $id,
        'type' => JobType::RetryReconcile->value,
        'resource_type' => 'website',
        'resource_id' => (string) Str::uuid7(),
        'dispatch_id' => (string) Str::uuid(),
        'status' => $status->value,
        'failure_class' => $status === JobStatus::Retrying ? 'retryable' : null,
        'attempts' => $attempts,
        'started_at' => $status === JobStatus::Pending ? null : $at,
        'finished_at' => $status->isFinished() ? $at : null,
        'created_at' => $at,
        'updated_at' => $at,
    ]);

    return $id;
}

describe('the sweep', function (): void {
    beforeEach(function (): void {
        $this->runner = new QueueRunner;

        config([
            'queue.default' => 'redis',
            'queue.connections.redis.queue' => $this->runner->queue,
        ]);
    });

    afterEach(function (): void {
        $this->runner->flush();
    });

    it('gives a new job to an unfinished row nobody touched for 15 minutes', function (JobStatus $status): void {
        $id = jobRowUntouchedFor(901, $status);
        $before = JobHistoryEntry::query()->findOrFail($id);

        expect(app(JobHistorySweeper::class)->sweep())->toBe(1)
            ->and($this->runner->size())->toBe(1);

        $after = JobHistoryEntry::query()->findOrFail($id);

        expect($after->dispatch_id)->not->toBe($before->dispatch_id)
            ->and(Str::isUuid($after->dispatch_id))->toBeTrue()
            ->and($after->updated_at->equalTo(CarbonImmutable::now()))->toBeTrue()
            ->and($after->status)->toBe($status)
            ->and($after->attempts)->toBe(0);
    })->with([JobStatus::Pending, JobStatus::Running, JobStatus::Waiting, JobStatus::Retrying]);

    it('leaves a row that changed within 15 minutes', function (): void {
        jobRowUntouchedFor(900);

        expect(app(JobHistorySweeper::class)->sweep())->toBe(0)
            ->and($this->runner->size())->toBe(0);
    });

    it('leaves a finished row', function (JobStatus $status): void {
        jobRowUntouchedFor(86400, $status);

        expect(app(JobHistorySweeper::class)->sweep())->toBe(0)
            ->and($this->runner->size())->toBe(0);
    })->with([JobStatus::Succeeded, JobStatus::Failed]);

    it('follows the configured age', function (): void {
        config(['hostmesh.jobs.sweep_after' => 60]);
        jobRowUntouchedFor(61);
        jobRowUntouchedFor(60);

        expect(app(JobHistorySweeper::class)->sweep())->toBe(1);
    });

    it('runs the job it gave back', function (): void {
        $id = jobRowUntouchedFor(901);

        app(JobHistorySweeper::class)->sweep();
        $this->runner->runNext();

        expect(JobHistoryEntry::query()->findOrFail($id)->status)->toBe(JobStatus::Succeeded)
            ->and($this->runner->size())->toBe(0);
    });

    // The double-delivery rule: Redis still held the old copy after all.
    // It must not run, or the two copies would each count the attempts.
    it('turns the copy that was there before into one that does nothing', function (): void {
        $id = jobRowUntouchedFor(901, JobStatus::Retrying, 2);
        ScriptedJob::$script = [JobFailure::retryable('service timeout')];
        $this->runner->push(new ScriptedJob($id, JobHistoryEntry::query()->findOrFail($id)->dispatch_id));

        app(JobHistorySweeper::class)->sweep();

        // The old copy comes first.
        $this->runner->runNext();

        expect(ScriptedJob::$runs)->toBe(0)
            ->and(JobHistoryEntry::query()->findOrFail($id)->status)->toBe(JobStatus::Retrying);

        $this->runner->runNext();

        $entry = JobHistoryEntry::query()->findOrFail($id);

        expect(ScriptedJob::$runs)->toBe(0)
            ->and($entry->status)->toBe(JobStatus::Succeeded)
            ->and($entry->attempts)->toBe(2)
            ->and($this->runner->size())->toBe(0);
    });

    // The new job may sit behind a long queue. Sending it to the back on
    // every run would starve it.
    it('gives a row its job back once, not on every run', function (): void {
        jobRowUntouchedFor(901);

        app(JobHistorySweeper::class)->sweep();
        $this->travel(5)->minutes();

        expect(app(JobHistorySweeper::class)->sweep())->toBe(0)
            ->and($this->runner->size())->toBe(1);
    });

    it('gives back at most 1000 rows a run, oldest first', function (): void {
        $youngest = jobRowUntouchedFor(901);

        foreach (range(1, 1000) as $minutes) {
            jobRowUntouchedFor(901 + $minutes * 60);
        }

        expect(app(JobHistorySweeper::class)->sweep())->toBe(1000)
            ->and($this->runner->size())->toBe(1000)
            ->and(app(JobHistorySweeper::class)->sweep())->toBe(1)
            ->and(JobHistoryEntry::query()->findOrFail($youngest)->updated_at->equalTo(CarbonImmutable::now()))->toBeTrue();
    });

    // PostgreSQL is authoritative: no new job for a token that was rolled back.
    it('pushes nothing when the surrounding transaction rolls back', function (): void {
        $id = jobRowUntouchedFor(901);
        $token = JobHistoryEntry::query()->findOrFail($id)->dispatch_id;

        try {
            DB::transaction(function (): void {
                app(JobHistorySweeper::class)->sweep();

                expect($this->runner->size())->toBe(0);

                throw new RuntimeException('rolled back');
            });
        } catch (RuntimeException) {
            //
        }

        expect($this->runner->size())->toBe(0)
            ->and(JobHistoryEntry::query()->findOrFail($id)->dispatch_id)->toBe($token);
    });
});

// Finished rows pile up for good. The sweep must not read through them.
it('indexes the unfinished rows by when they last changed', function (): void {
    $definition = DB::scalar("SELECT indexdef FROM pg_indexes WHERE tablename = 'job_history' AND indexname = 'job_history_unfinished_updated_at_index'");

    expect($definition)->toBe("CREATE INDEX job_history_unfinished_updated_at_index ON public.job_history USING btree (updated_at) WHERE ((status)::text = ANY ((ARRAY['pending'::character varying, 'running'::character varying, 'waiting'::character varying, 'retrying'::character varying])::text[]))");
});

it('gives every row a dispatch token, including the rows from before', function (): void {
    $column = DB::selectOne("SELECT data_type, is_nullable, column_default FROM information_schema.columns WHERE table_name = 'job_history' AND column_name = 'dispatch_id'");

    expect($column->data_type)->toBe('uuid')
        ->and($column->is_nullable)->toBe('NO')
        ->and($column->column_default)->toBeNull();
});

it('knows the job of every job type', function (JobType $type): void {
    expect(OneShotJobs::of($type)::type())->toBe($type);
})->with(JobType::cases());

it('is called sweep_job_history in the history', function (): void {
    expect(SweepJobHistory::type())->toBe(JobType::SweepJobHistory)
        ->and(SweepJobHistory::type()->value)->toBe('sweep_job_history');
});

// The test environment runs jobs at once (the sync connection), so the
// job the sweep gives back runs inside schedule:run.
describe('the schedule', function (): void {
    it('sweeps every five minutes', function (): void {
        $stale = jobRowUntouchedFor(901);
        $this->travelTo(CarbonImmutable::parse('2026-10-22 12:05:00', 'UTC'));

        $this->artisan('schedule:run')->assertSuccessful();

        $sweep = JobHistoryEntry::query()->where('type', JobType::SweepJobHistory)->sole();

        expect($sweep->status)->toBe(JobStatus::Succeeded)
            ->and($sweep->resource_type)->toBe(SweepPolicy::RESOURCE_TYPE)
            ->and($sweep->resource_id)->toBe(SweepPolicy::RESOURCE_ID)
            ->and(JobHistoryEntry::query()->findOrFail($stale)->status)->toBe(JobStatus::Succeeded);
    });

    it('does not sweep in between', function (): void {
        $this->travelTo(CarbonImmutable::parse('2026-10-22 12:03:00', 'UTC'));

        $this->artisan('schedule:run')->assertSuccessful();

        expect(JobHistoryEntry::query()->where('type', JobType::SweepJobHistory)->count())->toBe(0);
    });
});

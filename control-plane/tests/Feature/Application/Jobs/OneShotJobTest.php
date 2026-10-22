<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Application\Exceptions\JobFailure;
use App\Application\Services\JobHistory;
use App\Domain\Provisioning\FailureClass;
use App\Domain\Provisioning\Jitter;
use App\Domain\Provisioning\JobHistoryEntry;
use App\Domain\Provisioning\JobStatus;
use App\Domain\Provisioning\JobType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use Tests\Fixtures\ScriptedJob;
use Tests\Support\FixedJitter;
use Tests\Support\QueueRunner;

// Spec section 8, failure classes, on the real Redis queue. Decision 0014:
// the history row counts the attempts, not the queue.

uses(RefreshDatabase::class);

beforeEach(function (): void {
    ScriptedJob::$script = [];
    ScriptedJob::$runs = 0;

    // Full delays unless a test says otherwise.
    $this->app->instance(Jitter::class, new FixedJitter(0.0));
    $this->travelTo(CarbonImmutable::parse('2026-10-08 12:00:00', 'UTC'));

    $this->runner = new QueueRunner;
});

afterEach(function (): void {
    $this->runner->flush();
});

/** Opens a history row and pushes a ScriptedJob for it. Returns the row's ID. */
function pushScriptedJob(QueueRunner $runner): string
{
    $opened = app(JobHistory::class)->open(JobType::RetryReconcile, 'website', (string) Str::uuid7(), 3);

    $runner->push(new ScriptedJob($opened->id, $opened->dispatch_id));

    return $opened->id;
}

/** The dispatch token the row holds now. */
function dispatchTokenOf(string $id): string
{
    return JobHistoryEntry::query()->findOrFail($id)->dispatch_id;
}

it('records a success and leaves the queue', function (): void {
    $id = pushScriptedJob($this->runner);

    $this->runner->runNext();

    $entry = JobHistoryEntry::query()->findOrFail($id);

    expect(ScriptedJob::$runs)->toBe(1)
        ->and($entry->status)->toBe(JobStatus::Succeeded)
        ->and($entry->attempts)->toBe(0)
        ->and($entry->started_at)->not->toBeNull()
        ->and($entry->finished_at)->not->toBeNull()
        ->and($this->runner->size())->toBe(0);
});

it('backs off from 10 seconds to the cap and fails after 8 attempts', function (): void {
    ScriptedJob::$script = array_fill(0, 8, JobFailure::retryable('service timeout'));
    $id = pushScriptedJob($this->runner);

    foreach ([10, 20, 40, 80, 160, 320, 600] as $index => $delay) {
        $attempt = $index + 1;

        $this->runner->runNext();

        $entry = JobHistoryEntry::query()->findOrFail($id);

        expect(ScriptedJob::$runs)->toBe($attempt)
            ->and($entry->status)->toBe(JobStatus::Retrying)
            ->and($entry->attempts)->toBe($attempt)
            ->and($entry->message)->toBe('service timeout');

        // One second early, the job is not handed out.
        $this->travel($delay - 1)->seconds();
        $this->runner->runNext();

        expect(ScriptedJob::$runs)->toBe($attempt);

        $this->travel(1)->seconds();
    }

    $this->runner->runNext();

    $entry = JobHistoryEntry::query()->findOrFail($id);

    expect(ScriptedJob::$runs)->toBe(8)
        ->and($entry->status)->toBe(JobStatus::Failed)
        ->and($entry->failure_class)->toBe(FailureClass::Retryable)
        ->and($entry->attempts)->toBe(8)
        ->and($entry->finished_at)->not->toBeNull()
        ->and($this->runner->size())->toBe(0);

    // Failed is final: nothing comes back.
    $this->travel(1)->hours();
    $this->runner->runNext();

    expect(ScriptedJob::$runs)->toBe(8);
});

it('shortens the delay by the jitter', function (): void {
    $this->app->instance(Jitter::class, new FixedJitter(1.0));
    ScriptedJob::$script = [JobFailure::retryable('service timeout')];
    pushScriptedJob($this->runner);

    $this->runner->runNext();

    // Half of 10 seconds.
    $this->travel(4)->seconds();
    $this->runner->runNext();

    expect(ScriptedJob::$runs)->toBe(1);

    $this->travel(1)->seconds();
    $this->runner->runNext();

    expect(ScriptedJob::$runs)->toBe(2);
});

it('follows a configured policy', function (): void {
    config([
        'hostmesh.jobs.retry.base_delay' => 3,
        'hostmesh.jobs.retry.max_attempts' => 2,
    ]);
    ScriptedJob::$script = array_fill(0, 2, JobFailure::retryable('service timeout'));
    $id = pushScriptedJob($this->runner);

    $this->runner->runNext();
    $this->travel(3)->seconds();
    $this->runner->runNext();

    $entry = JobHistoryEntry::query()->findOrFail($id);

    expect(ScriptedJob::$runs)->toBe(2)
        ->and($entry->status)->toBe(JobStatus::Failed)
        ->and($entry->attempts)->toBe(2);
});

it('never counts waiting toward the attempt limit', function (): void {
    // Far more waits than there are attempts, then a success.
    ScriptedJob::$script = [...array_fill(0, 20, JobFailure::waiting('DNS not propagated')), null];
    $id = pushScriptedJob($this->runner);

    foreach (range(1, 20) as $run) {
        $this->runner->runNext();

        $entry = JobHistoryEntry::query()->findOrFail($id);

        expect(ScriptedJob::$runs)->toBe($run)
            ->and($entry->status)->toBe(JobStatus::Waiting)
            ->and($entry->failure_class)->toBe(FailureClass::Waiting)
            ->and($entry->attempts)->toBe(0);

        // The default recheck, one second early.
        $this->travel(119)->seconds();
        $this->runner->runNext();

        expect(ScriptedJob::$runs)->toBe($run);

        $this->travel(1)->seconds();
    }

    $this->runner->runNext();

    $entry = JobHistoryEntry::query()->findOrFail($id);

    expect(ScriptedJob::$runs)->toBe(21)
        ->and($entry->status)->toBe(JobStatus::Succeeded)
        ->and($entry->attempts)->toBe(0);
});

it('rechecks when a waiting job says to', function (): void {
    ScriptedJob::$script = [JobFailure::waiting('ACME retry-after', 30), null];
    pushScriptedJob($this->runner);

    $this->runner->runNext();
    $this->travel(29)->seconds();
    $this->runner->runNext();

    expect(ScriptedJob::$runs)->toBe(1);

    $this->travel(1)->seconds();
    $this->runner->runNext();

    expect(ScriptedJob::$runs)->toBe(2);
});

it('fails immediately on a terminal failure', function (): void {
    ScriptedJob::$script = [JobFailure::terminal('unsupported PHP version')];
    $id = pushScriptedJob($this->runner);

    $this->runner->runNext();

    $entry = JobHistoryEntry::query()->findOrFail($id);

    expect(ScriptedJob::$runs)->toBe(1)
        ->and($entry->status)->toBe(JobStatus::Failed)
        ->and($entry->failure_class)->toBe(FailureClass::Terminal)
        ->and($entry->message)->toBe('unsupported PHP version')
        ->and($entry->attempts)->toBe(0)
        ->and($entry->finished_at)->not->toBeNull()
        ->and($this->runner->size())->toBe(0);

    $this->travel(1)->hours();
    $this->runner->runNext();

    expect(ScriptedJob::$runs)->toBe(1);
});

// The `queue:work` command is what writes failed_jobs, so this test runs
// the command instead of the bare worker.
it('stores a failed job in failed_jobs in PostgreSQL', function (): void {
    ScriptedJob::$script = [JobFailure::terminal('quota exceeded')];
    pushScriptedJob($this->runner);

    $this->artisan('queue:work', [
        'connection' => 'redis',
        '--queue' => $this->runner->queue,
        '--once' => true,
        '--sleep' => 0,
    ])->assertSuccessful();

    expect(DB::table('failed_jobs')->where('queue', $this->runner->queue)->count())->toBe(1);
});

it('treats an unexpected exception as retryable and reports it', function (): void {
    Exceptions::fake();
    ScriptedJob::$script = [new RuntimeException('no route to redis://:s3cret@redis'), null];
    $id = pushScriptedJob($this->runner);

    $this->runner->runNext();

    $entry = JobHistoryEntry::query()->findOrFail($id);

    expect($entry->status)->toBe(JobStatus::Retrying)
        ->and($entry->failure_class)->toBe(FailureClass::Retryable)
        ->and($entry->attempts)->toBe(1)
        // The class only: the text of a foreign exception can hold a secret.
        ->and($entry->message)->toBe('RuntimeException');

    Exceptions::assertReported(RuntimeException::class);

    $this->travel(10)->seconds();
    $this->runner->runNext();

    $entry = JobHistoryEntry::query()->findOrFail($id);

    expect($entry->status)->toBe(JobStatus::Succeeded)
        ->and($entry->attempts)->toBe(1);
});

it('does not report a failure the job classified itself', function (): void {
    Exceptions::fake();
    ScriptedJob::$script = [JobFailure::retryable('service timeout')];
    pushScriptedJob($this->runner);

    $this->runner->runNext();

    Exceptions::assertNothingReported();
});

// A run that died leaves the row running: a killed worker, a timeout.
it('counts an interrupted run and carries on', function (): void {
    $id = pushScriptedJob($this->runner);
    app(JobHistory::class)->begin($id, dispatchTokenOf($id), 60);
    $this->travel(60)->seconds();

    $this->runner->runNext();

    $entry = JobHistoryEntry::query()->findOrFail($id);

    expect(ScriptedJob::$runs)->toBe(1)
        ->and($entry->status)->toBe(JobStatus::Succeeded)
        ->and($entry->attempts)->toBe(1);
});

it('fails a job that is interrupted on its last attempt, without running it', function (): void {
    $id = pushScriptedJob($this->runner);
    JobHistoryEntry::query()->whereKey($id)->update(['status' => 'running', 'attempts' => 7]);
    $this->travel(60)->seconds();

    $this->runner->runNext();

    $entry = JobHistoryEntry::query()->findOrFail($id);

    expect(ScriptedJob::$runs)->toBe(0)
        ->and($entry->status)->toBe(JobStatus::Failed)
        ->and($entry->failure_class)->toBe(FailureClass::Retryable)
        ->and($entry->attempts)->toBe(8)
        ->and($entry->message)->toBe(JobHistory::INTERRUPTED)
        ->and($this->runner->size())->toBe(0);
});

// The last failure was counted, and the worker died before the row was
// closed. The row must keep what really went wrong.
it('fails a job that comes back with its attempts used up, and keeps its message', function (): void {
    $id = pushScriptedJob($this->runner);
    JobHistoryEntry::query()->whereKey($id)->update([
        'status' => 'retrying',
        'failure_class' => 'retryable',
        'message' => 'service timeout',
        'attempts' => 8,
    ]);

    $this->runner->runNext();

    $entry = JobHistoryEntry::query()->findOrFail($id);

    expect(ScriptedJob::$runs)->toBe(0)
        ->and($entry->status)->toBe(JobStatus::Failed)
        ->and($entry->failure_class)->toBe(FailureClass::Retryable)
        ->and($entry->attempts)->toBe(8)
        ->and($entry->message)->toBe('service timeout')
        ->and($this->runner->size())->toBe(0);
});

// An operator lowered the limit while the job waited. Waiting is not a
// failure, so the job still gets its look.
it('runs a waiting job whose attempts are beyond a lowered limit', function (): void {
    config(['hostmesh.jobs.retry.max_attempts' => 3]);
    $id = pushScriptedJob($this->runner);
    JobHistoryEntry::query()->whereKey($id)->update([
        'status' => 'waiting',
        'failure_class' => 'waiting',
        'message' => 'DNS not propagated',
        'attempts' => 4,
    ]);

    $this->runner->runNext();

    expect(ScriptedJob::$runs)->toBe(1)
        ->and(JobHistoryEntry::query()->findOrFail($id)->status)->toBe(JobStatus::Succeeded);
});

// Redis handed the job to a second worker, or somebody pushed it again,
// while the first run is inside its timeout.
it('leaves a run that may still be alive alone, and comes back when its time is up', function (): void {
    $id = pushScriptedJob($this->runner);
    app(JobHistory::class)->begin($id, dispatchTokenOf($id), 60);

    $this->runner->runNext();

    $entry = JobHistoryEntry::query()->findOrFail($id);

    expect(ScriptedJob::$runs)->toBe(0)
        ->and($entry->status)->toBe(JobStatus::Running)
        ->and($entry->attempts)->toBe(0)
        ->and($this->runner->size())->toBe(1);

    $this->travel(59)->seconds();
    $this->runner->runNext();

    expect(ScriptedJob::$runs)->toBe(0);

    // The timeout has passed and the row is still running: that run died.
    $this->travel(1)->seconds();
    $this->runner->runNext();

    $entry = JobHistoryEntry::query()->findOrFail($id);

    expect(ScriptedJob::$runs)->toBe(1)
        ->and($entry->status)->toBe(JobStatus::Succeeded)
        ->and($entry->attempts)->toBe(1);
});

it('drops the second delivery when the first run finished meanwhile', function (): void {
    $id = pushScriptedJob($this->runner);
    $history = app(JobHistory::class);
    $entry = $history->begin($id, dispatchTokenOf($id), 60);

    $this->runner->runNext();
    $history->succeeded($entry);

    $this->travel(60)->seconds();
    $this->runner->runNext();

    expect(ScriptedJob::$runs)->toBe(0)
        ->and($this->runner->size())->toBe(0);
});

// Whatever goes wrong before the row is settled leaves handle(): a wrong
// setting, PostgreSQL away. The queue has no try limit for these jobs, so
// it must at least pause before it hands the job out again.
it('pauses before the queue redelivers a job that could not start', function (): void {
    Exceptions::fake();
    config(['hostmesh.jobs.retry.max_attempts' => 0]);
    $id = pushScriptedJob($this->runner);

    $this->runner->runNext();

    Exceptions::assertReported(InvalidArgumentException::class);

    expect(JobHistoryEntry::query()->findOrFail($id)->status)->toBe(JobStatus::Pending)
        ->and($this->runner->size())->toBe(1);

    config(['hostmesh.jobs.retry.max_attempts' => 8]);
    $this->travel(9)->seconds();
    $this->runner->runNext();

    expect(ScriptedJob::$runs)->toBe(0);

    $this->travel(1)->seconds();
    $this->runner->runNext();

    expect(ScriptedJob::$runs)->toBe(1)
        ->and(JobHistoryEntry::query()->findOrFail($id)->status)->toBe(JobStatus::Succeeded);
});

// Redis delivers at least once, and an operator can re-push a failed job.
it('does not run again for a row that is finished', function (): void {
    $id = pushScriptedJob($this->runner);
    $this->runner->push(new ScriptedJob($id, dispatchTokenOf($id)));

    $this->runner->runNext();
    $this->runner->runNext();

    expect(ScriptedJob::$runs)->toBe(1)
        ->and($this->runner->size())->toBe(0);
});

// Issue #155: the sweep gave the row a new job and a new token, and Redis
// still held the old copy.
it('drops a copy whose token the row no longer holds', function (): void {
    $id = pushScriptedJob($this->runner);
    JobHistoryEntry::query()->whereKey($id)->update(['dispatch_id' => (string) Str::uuid()]);

    $this->runner->runNext();

    $entry = JobHistoryEntry::query()->findOrFail($id);

    expect(ScriptedJob::$runs)->toBe(0)
        ->and($entry->status)->toBe(JobStatus::Pending)
        ->and($entry->started_at)->toBeNull()
        ->and($this->runner->size())->toBe(0);
});

it('drops a job whose row does not exist', function (string $id): void {
    $this->runner->push(new ScriptedJob($id, (string) Str::uuid()));

    $this->runner->runNext();

    expect(ScriptedJob::$runs)->toBe(0)
        ->and($this->runner->size())->toBe(0);
})->with([
    'unknown' => '0199c1d2-0000-7000-8000-00000000dead',
    'malformed' => 'not-a-uuid',
]);

// Laravel must not count: 0 means no limit. And one run must end before
// Redis hands the job to a second worker.
it('leaves the counting to the history row and ends before the queue redelivers', function (): void {
    $job = new ScriptedJob((string) Str::uuid7(), (string) Str::uuid());

    expect($job->tries)->toBe(0)
        ->and($job->backoff)->toBeGreaterThan(0)
        ->and($job->timeout)->toBeLessThan(config('queue.connections.redis.retry_after'));
});

<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Application\Services\JobHistory;
use App\Domain\Provisioning\FailureClass;
use App\Domain\Provisioning\JobHistoryEntry;
use App\Domain\Provisioning\JobStatus;
use App\Domain\Provisioning\JobType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Spec section 6: what was attempted, for which generation, on which node,
// with what result. Decision 0014.

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 12:00:00', 'UTC'));
});

it('opens a row with the resource, the generation and the node', function (): void {
    $resource = (string) Str::uuid7();
    $node = (string) Str::uuid7();

    $opened = app(JobHistory::class)->open(JobType::RetryReconcile, 'website', $resource, 7, $node);

    $entry = JobHistoryEntry::query()->sole();

    expect($entry->id)->toBe($opened->id)
        ->and(Str::isUuid($entry->id))->toBeTrue()
        ->and($entry->type)->toBe(JobType::RetryReconcile)
        ->and($entry->resource_type)->toBe('website')
        ->and($entry->resource_id)->toBe($resource)
        ->and($entry->generation)->toBe(7)
        ->and($entry->node_id)->toBe($node)
        ->and($entry->status)->toBe(JobStatus::Pending)
        ->and($entry->failure_class)->toBeNull()
        ->and($entry->message)->toBeNull()
        ->and($entry->attempts)->toBe(0)
        ->and($entry->started_at)->toBeNull()
        ->and($entry->finished_at)->toBeNull()
        ->and($entry->created_at->equalTo('2026-10-08 12:00:00 UTC'))->toBeTrue();
});

it('stores short stable names, never class names', function (): void {
    app(JobHistory::class)->open(JobType::RetryReconcile, 'website', (string) Str::uuid7());

    $row = DB::table('job_history')->sole();

    expect($row->type)->toBe('retry_reconcile')
        ->and($row->status)->toBe('pending');
});

it('opens a row without a generation and a node', function (): void {
    app(JobHistory::class)->open(JobType::RetryReconcile, 'node', (string) Str::uuid7());

    $entry = JobHistoryEntry::query()->sole();

    expect($entry->generation)->toBeNull()
        ->and($entry->node_id)->toBeNull();
});

it('records when a run starts, and keeps the first start', function (): void {
    $history = app(JobHistory::class);
    $id = $history->open(JobType::RetryReconcile, 'website', (string) Str::uuid7())->id;

    $this->travel(5)->seconds();
    $entry = $history->begin($id);

    expect($entry)->not->toBeNull()
        ->and($entry->status)->toBe(JobStatus::Running)
        ->and($entry->started_at->equalTo('2026-10-08 12:00:05 UTC'))->toBeTrue()
        ->and($entry->attempts)->toBe(0);

    $history->waiting($entry, 'DNS not propagated');
    $this->travel(2)->minutes();
    $again = $history->begin($id);

    expect($again->started_at->equalTo('2026-10-08 12:00:05 UTC'))->toBeTrue();
});

it('records a success with the time it finished', function (): void {
    $history = app(JobHistory::class);
    $entry = $history->begin($history->open(JobType::RetryReconcile, 'website', (string) Str::uuid7())->id);

    $this->travel(3)->seconds();
    $history->succeeded($entry);

    $stored = JobHistoryEntry::query()->sole();

    expect($stored->status)->toBe(JobStatus::Succeeded)
        ->and($stored->failure_class)->toBeNull()
        ->and($stored->message)->toBeNull()
        ->and($stored->finished_at->equalTo('2026-10-08 12:00:03 UTC'))->toBeTrue();
});

it('counts a retryable failure and keeps its message', function (): void {
    $history = app(JobHistory::class);
    $entry = $history->begin($history->open(JobType::RetryReconcile, 'website', (string) Str::uuid7())->id);

    $attempts = $history->retrying($entry, 'service timeout');

    $stored = JobHistoryEntry::query()->sole();

    expect($attempts)->toBe(1)
        ->and($stored->attempts)->toBe(1)
        ->and($stored->status)->toBe(JobStatus::Retrying)
        ->and($stored->failure_class)->toBe(FailureClass::Retryable)
        ->and($stored->message)->toBe('service timeout')
        ->and($stored->finished_at)->toBeNull();
});

it('does not count a wait as an attempt', function (): void {
    $history = app(JobHistory::class);
    $entry = $history->begin($history->open(JobType::RetryReconcile, 'website', (string) Str::uuid7())->id);

    $history->waiting($entry, 'DNS not propagated');

    $stored = JobHistoryEntry::query()->sole();

    expect($stored->attempts)->toBe(0)
        ->and($stored->status)->toBe(JobStatus::Waiting)
        ->and($stored->failure_class)->toBe(FailureClass::Waiting)
        ->and($stored->message)->toBe('DNS not propagated')
        ->and($stored->finished_at)->toBeNull();
});

it('records a failure with its class, its message and the time it finished', function (): void {
    $history = app(JobHistory::class);
    $entry = $history->begin($history->open(JobType::RetryReconcile, 'website', (string) Str::uuid7())->id);

    $this->travel(1)->seconds();
    $history->failed($entry, FailureClass::Terminal, 'unsupported PHP version');

    $stored = JobHistoryEntry::query()->sole();

    expect($stored->status)->toBe(JobStatus::Failed)
        ->and($stored->failure_class)->toBe(FailureClass::Terminal)
        ->and($stored->message)->toBe('unsupported PHP version')
        ->and($stored->attempts)->toBe(0)
        ->and($stored->finished_at->equalTo('2026-10-08 12:00:01 UTC'))->toBeTrue();
});

// A row that is still running when the job comes back means the previous
// run died: a killed worker, a timeout.
it('counts an interrupted run as one retryable attempt', function (): void {
    $history = app(JobHistory::class);
    $id = $history->open(JobType::RetryReconcile, 'website', (string) Str::uuid7())->id;

    $history->begin($id);
    $entry = $history->begin($id);

    expect($entry->attempts)->toBe(1)
        ->and($entry->status)->toBe(JobStatus::Running)
        ->and($entry->failure_class)->toBe(FailureClass::Retryable)
        ->and($entry->message)->toBe(JobHistory::INTERRUPTED);
});

it('answers null for a row it cannot run', function (string $state): void {
    $history = app(JobHistory::class);
    $entry = $history->begin($history->open(JobType::RetryReconcile, 'website', (string) Str::uuid7())->id);

    $id = match ($state) {
        'succeeded' => tap($entry->id, fn () => $history->succeeded($entry)),
        'failed' => tap($entry->id, fn () => $history->failed($entry, FailureClass::Terminal, 'no')),
        'unknown' => (string) Str::uuid7(),
        'malformed' => 'not-a-uuid',
    };

    expect($history->begin($id))->toBeNull();

    // A malformed UUID must not have broken the transaction.
    expect(JobHistoryEntry::query()->count())->toBe(1);
})->with(['succeeded', 'failed', 'unknown', 'malformed']);

it('cuts a message to 1000 characters, never inside a character', function (): void {
    $history = app(JobHistory::class);
    $entry = $history->begin($history->open(JobType::RetryReconcile, 'website', (string) Str::uuid7())->id);

    $history->retrying($entry, str_repeat('ü', 1500));

    $stored = JobHistoryEntry::query()->sole();

    expect(mb_strlen((string) $stored->message))->toBe(1000)
        ->and($stored->message)->toBe(str_repeat('ü', 1000));
});

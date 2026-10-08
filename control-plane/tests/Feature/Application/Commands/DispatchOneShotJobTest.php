<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Application\Commands\DispatchOneShotJob;
use App\Application\Jobs\RetryReconcile;
use App\Domain\Provisioning\JobHistoryEntry;
use App\Domain\Provisioning\JobStatus;
use App\Domain\Provisioning\JobType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\QueueRunner;

// Spec section 6: true jobs are one-shot actions that are not state.
// Section 8 names the first one, retry_reconcile. Until resources exist
// it does nothing but leave its history row.

uses(RefreshDatabase::class);

it('is called retry_reconcile in the history', function (): void {
    expect(RetryReconcile::type())->toBe(JobType::RetryReconcile)
        ->and(RetryReconcile::type()->value)->toBe('retry_reconcile');
});

// The test environment runs jobs at once (the sync connection).
it('runs retry_reconcile and records a history row', function (): void {
    $resource = (string) Str::uuid7();
    $node = (string) Str::uuid7();

    $opened = app(DispatchOneShotJob::class)->handle(RetryReconcile::class, 'website', $resource, 4, $node);

    $entry = JobHistoryEntry::query()->sole();

    expect($entry->id)->toBe($opened->id)
        ->and($entry->type)->toBe(JobType::RetryReconcile)
        ->and($entry->resource_type)->toBe('website')
        ->and($entry->resource_id)->toBe($resource)
        ->and($entry->generation)->toBe(4)
        ->and($entry->node_id)->toBe($node)
        ->and($entry->status)->toBe(JobStatus::Succeeded)
        ->and($entry->attempts)->toBe(0)
        ->and($entry->started_at)->not->toBeNull()
        ->and($entry->finished_at)->not->toBeNull();
});

describe('on the Redis queue', function (): void {
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

    it('leaves a pending row until a worker runs the job', function (): void {
        $opened = app(DispatchOneShotJob::class)->handle(RetryReconcile::class, 'website', (string) Str::uuid7());

        expect($opened->status)->toBe(JobStatus::Pending)
            ->and($this->runner->size())->toBe(1);

        $this->runner->runNext();

        expect(JobHistoryEntry::query()->sole()->status)->toBe(JobStatus::Succeeded)
            ->and($this->runner->size())->toBe(0);
    });

    // PostgreSQL is authoritative: no job without its row, no push for a
    // row that was rolled back.
    it('pushes nothing when the surrounding transaction rolls back', function (): void {
        try {
            DB::transaction(function (): void {
                app(DispatchOneShotJob::class)->handle(RetryReconcile::class, 'website', (string) Str::uuid7());

                expect($this->runner->size())->toBe(0);

                throw new RuntimeException('rolled back');
            });
        } catch (RuntimeException) {
            //
        }

        expect($this->runner->size())->toBe(0)
            ->and(JobHistoryEntry::query()->count())->toBe(0);
    });

    it('pushes once the surrounding transaction commits', function (): void {
        DB::transaction(function (): void {
            app(DispatchOneShotJob::class)->handle(RetryReconcile::class, 'website', (string) Str::uuid7());

            expect($this->runner->size())->toBe(0);
        });

        expect($this->runner->size())->toBe(1);
    });
});

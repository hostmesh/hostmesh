<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Application\Jobs;

use App\Application\Exceptions\JobFailure;
use App\Application\Exceptions\JobStillRunning;
use App\Application\Services\JobHistory;
use App\Domain\Provisioning\FailureClass;
use App\Domain\Provisioning\Jitter;
use App\Domain\Provisioning\JobHistoryEntry;
use App\Domain\Provisioning\JobType;
use App\Domain\Provisioning\RetryPolicy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * A one-shot action that is not state (spec section 6): it runs once on
 * the queue and leaves its result in `job_history`. A subclass says what
 * it is (type) and does the work (perform). Everything else is here.
 *
 * The outcome follows the failure classes of spec section 8, and the
 * history row counts the attempts (decision 0014): the queue's own
 * counters would count a wait as an attempt. Dispatch a job through
 * App\Application\Commands\DispatchOneShotJob, which creates the row.
 */
abstract class OneShotJob implements ShouldQueue
{
    use Queueable;

    /**
     * 0 tells the queue not to limit the tries. The history row does.
     */
    public int $tries = 0;

    /**
     * Seconds one run may take. It must stay below `retry_after` of the
     * queue connection, or Redis hands the job to a second worker while
     * the first still runs.
     */
    public int $timeout = 60;

    /**
     * Seconds before the queue hands the job out again when handle()
     * itself threw: a wrong setting, PostgreSQL away. There is no try
     * limit, so without a pause the worker would spin.
     */
    public int $backoff = 10;

    final public function __construct(
        public readonly string $historyId,
    ) {
        //
    }

    /**
     * What this job is called in `job_history`.
     */
    abstract public static function type(): JobType;

    /**
     * Does the work. Throw a JobFailure to say how it went wrong.
     * Returning means success.
     */
    abstract protected function perform(JobHistoryEntry $entry): void;

    /**
     * @throws Throwable when the history row cannot be written; the queue then delivers the job again after `backoff`
     */
    final public function handle(JobHistory $history, RetryPolicy $policy, Jitter $jitter): void
    {
        try {
            $entry = $history->begin($this->historyId, $this->timeout);
        } catch (JobStillRunning $running) {
            // Delivered twice while the first run may be alive. Not an attempt.
            $this->release($running->retryIn);

            return;
        }

        // Unknown or finished: delivered twice, or pushed again by hand.
        if ($entry === null) {
            return;
        }

        // The last attempt was already counted: by begin() for a run that
        // was interrupted, or by a run that died before it closed the row.
        // The row keeps the message of that failure. A waiting row is not
        // a failure and runs, whatever its count.
        if ($entry->failure_class === FailureClass::Retryable && $policy->exhausted($entry->attempts)) {
            $message = $entry->message ?? JobHistory::INTERRUPTED;

            $history->failed($entry, FailureClass::Retryable, $message);
            $this->fail(JobFailure::retryable($message));

            return;
        }

        try {
            $this->perform($entry);
        } catch (JobFailure $failure) {
            $this->settle($entry, $failure, $history, $policy, $jitter);

            return;
        } catch (Throwable $e) {
            // A failure nobody classified is a bug or an outage we did not
            // foresee. The operator must see it; the job gets its attempts.
            // The row gets the class only: the text of an exception we did
            // not write can hold a secret (a query, a connection string).
            report($e);

            $this->settle($entry, JobFailure::retryable($e::class, $e), $history, $policy, $jitter);

            return;
        }

        $history->succeeded($entry);
    }

    private function settle(
        JobHistoryEntry $entry,
        JobFailure $failure,
        JobHistory $history,
        RetryPolicy $policy,
        Jitter $jitter,
    ): void {
        $message = $failure->getMessage();

        if ($failure->failureClass === FailureClass::Terminal) {
            $history->failed($entry, FailureClass::Terminal, $message);
            $this->fail($failure);

            return;
        }

        if ($failure->failureClass === FailureClass::Waiting) {
            $history->waiting($entry, $message);
            $this->release($failure->recheckAfter ?? $policy->recheckDelay);

            return;
        }

        $attempts = $history->retrying($entry, $message);

        if ($policy->exhausted($attempts)) {
            $history->failed($entry, FailureClass::Retryable, $message);
            $this->fail($failure);

            return;
        }

        $this->release($policy->delayAfter($attempts, $jitter->next()));
    }
}

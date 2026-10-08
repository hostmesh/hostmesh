<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Application\Exceptions\JobFailure;
use App\Domain\Provisioning\FailureClass;

it('names its failure class', function (): void {
    $cause = new RuntimeException('connection reset');

    $retryable = JobFailure::retryable('service timeout', $cause);
    $waiting = JobFailure::waiting('DNS not propagated', 30);
    $terminal = JobFailure::terminal('unsupported PHP version');

    expect($retryable->failureClass)->toBe(FailureClass::Retryable)
        ->and($retryable->getMessage())->toBe('service timeout')
        ->and($retryable->getPrevious())->toBe($cause)
        ->and($retryable->recheckAfter)->toBeNull()
        ->and($waiting->failureClass)->toBe(FailureClass::Waiting)
        ->and($waiting->recheckAfter)->toBe(30)
        ->and(JobFailure::waiting('later')->recheckAfter)->toBeNull()
        ->and($terminal->failureClass)->toBe(FailureClass::Terminal)
        ->and($terminal->getMessage())->toBe('unsupported PHP version');
});

it('refuses a recheck that is not in the future', function (int $seconds): void {
    expect(fn (): JobFailure => JobFailure::waiting('later', $seconds))
        ->toThrow(InvalidArgumentException::class);
})->with([0, -5]);

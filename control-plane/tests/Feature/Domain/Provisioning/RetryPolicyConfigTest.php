<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Domain\Provisioning\Jitter;
use App\Domain\Provisioning\RetryPolicy;
use App\Infrastructure\Provisioning\RandomJitter;

// Spec section 8: "All numbers are defaults and must be configurable."

it('defaults to the numbers of the specification', function (): void {
    $policy = app(RetryPolicy::class);

    expect($policy->baseDelay)->toBe(10)
        ->and($policy->maxDelay)->toBe(600)
        ->and($policy->maxAttempts)->toBe(8)
        ->and($policy->jitter)->toBe(0.5)
        ->and($policy->recheckDelay)->toBe(120);
});

it('reads every number from the configuration', function (): void {
    config([
        'hostmesh.jobs.retry.base_delay' => 2,
        'hostmesh.jobs.retry.max_delay' => 30,
        'hostmesh.jobs.retry.max_attempts' => 3,
        'hostmesh.jobs.retry.jitter' => 0.25,
        'hostmesh.jobs.recheck_delay' => 45,
    ]);

    $policy = app(RetryPolicy::class);

    expect($policy->baseDelay)->toBe(2)
        ->and($policy->maxDelay)->toBe(30)
        ->and($policy->maxAttempts)->toBe(3)
        ->and($policy->jitter)->toBe(0.25)
        ->and($policy->recheckDelay)->toBe(45);
});

it('refuses a configuration that makes no sense', function (): void {
    config(['hostmesh.jobs.retry.max_attempts' => 0]);

    expect(fn (): RetryPolicy => app(RetryPolicy::class))->toThrow(InvalidArgumentException::class);
});

it('draws the jitter between zero and one', function (): void {
    $jitter = app(Jitter::class);

    expect($jitter)->toBeInstanceOf(RandomJitter::class);

    foreach (range(1, 200) as $ignored) {
        expect($jitter->next())->toBeGreaterThanOrEqual(0.0)->toBeLessThanOrEqual(1.0);
    }
});

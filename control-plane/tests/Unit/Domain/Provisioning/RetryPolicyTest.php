<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Domain\Provisioning\RetryPolicy;

// Spec section 8: exponential backoff from 10 s with jitter, capped at
// 10 minutes. After 8 attempts the status becomes failed.

it('doubles from the base delay and stops at the cap', function (): void {
    $policy = new RetryPolicy(10, 600, 8, 0.5, 120);

    $delays = array_map(fn (int $attempts): int => $policy->delayAfter($attempts, 0.0), range(1, 8));

    expect($delays)->toBe([10, 20, 40, 80, 160, 320, 600, 600]);
});

it('shortens a delay by at most the jitter ratio', function (): void {
    $policy = new RetryPolicy(10, 600, 8, 0.5, 120);

    expect($policy->delayAfter(3, 0.0))->toBe(40)
        ->and($policy->delayAfter(3, 0.5))->toBe(30)
        ->and($policy->delayAfter(3, 1.0))->toBe(20)
        // The cap holds with any jitter.
        ->and($policy->delayAfter(8, 0.0))->toBe(600)
        ->and($policy->delayAfter(8, 1.0))->toBe(300);
});

it('applies no jitter when the ratio is zero', function (): void {
    $policy = new RetryPolicy(10, 600, 8, 0.0, 120);

    expect($policy->delayAfter(2, 1.0))->toBe(20);
});

it('never waits less than a second', function (): void {
    $policy = new RetryPolicy(1, 600, 8, 1.0, 120);

    expect($policy->delayAfter(1, 1.0))->toBe(1);
});

it('stays at the cap however many attempts were made', function (): void {
    $policy = new RetryPolicy(10, 600, 1000, 0.5, 120);

    expect($policy->delayAfter(64, 0.0))->toBe(600)
        ->and($policy->delayAfter(1000, 0.0))->toBe(600);
});

it('is exhausted at the attempt limit', function (): void {
    $policy = new RetryPolicy(10, 600, 8, 0.5, 120);

    expect($policy->exhausted(0))->toBeFalse()
        ->and($policy->exhausted(7))->toBeFalse()
        ->and($policy->exhausted(8))->toBeTrue()
        ->and($policy->exhausted(9))->toBeTrue();
});

it('refuses settings that make no sense', function (int $base, int $max, int $attempts, float $jitter, int $recheck): void {
    expect(fn (): RetryPolicy => new RetryPolicy($base, $max, $attempts, $jitter, $recheck))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'no base delay' => [0, 600, 8, 0.5, 120],
    'cap below the base delay' => [10, 5, 8, 0.5, 120],
    'no attempt' => [10, 600, 0, 0.5, 120],
    'negative jitter' => [10, 600, 8, -0.1, 120],
    'jitter above one' => [10, 600, 8, 1.5, 120],
    'no recheck delay' => [10, 600, 8, 0.5, 0],
]);

it('refuses to compute a delay from nonsense', function (int $attempts, float $random): void {
    $policy = new RetryPolicy(10, 600, 8, 0.5, 120);

    expect(fn (): int => $policy->delayAfter($attempts, $random))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'before the first attempt' => [0, 0.5],
    'random below zero' => [1, -0.1],
    'random above one' => [1, 1.1],
]);

<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Domain\Audit\RetentionPolicy;

// Spec section 21 and decision 0013: events are kept for 24 months and
// ip_address and user_agent for 90 days, both configurable. Deletion has
// a floor of 12 months that is not configurable.

it('keeps the configured periods', function (): void {
    $policy = new RetentionPolicy(24, 90);

    expect($policy->eventMonths)->toBe(24)
        ->and($policy->addressDays)->toBe(90);
});

it('never keeps events for less than the floor of 12 months', function (int $configured): void {
    expect((new RetentionPolicy($configured, 90))->eventMonths)->toBe(12);
})->with([11, 6, 0, -3]);

it('allows the floor itself', function (): void {
    expect((new RetentionPolicy(12, 90))->eventMonths)->toBe(12);
});

it('refuses an address period of less than a day', function (int $days): void {
    new RetentionPolicy(24, $days);
})->with([0, -1])->throws(InvalidArgumentException::class, 'at least 1 day');

it('allows clearing addresses after a single day', function (): void {
    expect((new RetentionPolicy(24, 1))->addressDays)->toBe(1);
});

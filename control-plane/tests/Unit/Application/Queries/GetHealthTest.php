<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Application\Queries\GetHealth;
use App\Application\Queries\HealthStatus;

it('reports ok', function (): void {
    expect((new GetHealth)->handle())->toBe(HealthStatus::Ok);
});

it('serialises ok as the string the API returns', function (): void {
    expect(HealthStatus::Ok->value)->toBe('ok');
});
